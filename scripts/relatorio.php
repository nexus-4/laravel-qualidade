<?php

/*
 * Lê os relatórios das ferramentas e separa o que está nas linhas que a branch mudou do que já existia
 * nos arquivos tocados. Roda dentro do container, a partir de /app.
 *
 * Uso: php relatorio.php <phpstan|rector|cobertura|infection> <merge-base>
 * Cada comando imprime o detalhe e grava uma linha de resumo em /relatorios/resumo-<comando>.txt.
 */

const REPORTS = '/relatorios';

[$script, $tool, $base] = $argv + [null, null, null];

if (!in_array($tool, ['phpstan', 'rector', 'cobertura', 'infection'], true) || $base === null) {
    fwrite(STDERR, "Uso: php relatorio.php <phpstan|rector|cobertura|infection> <merge-base>\n");
    exit(2);
}

$changed = changedLines($base);

exit(match ($tool) {
    'phpstan'   => phpstan($changed),
    'rector'    => rector($changed),
    'cobertura' => coverage($changed),
    'infection' => infection(),
});

/**
 * @return array<string, true|array<int, true>> arquivo => true (arquivo novo) ou as linhas alteradas
 */
function changedLines(string $base): array
{
    $changed = [];
    $file    = null;

    foreach (explode("\n", (string) shell_exec('git diff -U0 --no-color ' . escapeshellarg($base) . ' -- app')) as $line) {
        if (str_starts_with($line, '+++ ')) {
            $file = $line === '+++ /dev/null' ? null : substr($line, 6);

            continue;
        }

        if ($file !== null && preg_match('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $line, $match)) {
            $count = isset($match[2]) ? (int) $match[2] : 1;

            for ($offset = 0; $offset < $count; $offset++) {
                $changed[$file][(int) $match[1] + $offset] = true;
            }
        }
    }

    foreach (array_filter(explode("\n", (string) shell_exec('git ls-files --others --exclude-standard -- app'))) as $file) {
        $changed[$file] = true;
    }

    return $changed;
}

function isChanged(array $changed, string $file, int $line): bool
{
    return ($changed[$file] ?? null) === true || isset($changed[$file][$line]);
}

function relative(string $path): string
{
    return preg_replace('#^/app/#', '', $path);
}

function writeSummary(string $tool, string $line): void
{
    file_put_contents(REPORTS . "/resumo-$tool.txt", $line);
}

function heading(string $title): void
{
    echo "\n── $title " . str_repeat('─', max(0, 70 - mb_strlen($title))) . "\n";
}

function phpstan(array $changed): int
{
    $report = json_decode((string) @file_get_contents(REPORTS . '/phpstan.json'), true);

    if (!is_array($report)) {
        writeSummary('phpstan', 'não rodou (veja phpstan.txt)');

        return 1;
    }

    $mine = [];
    $old  = 0;

    foreach ($report['files'] ?? [] as $path => $file) {
        foreach ($file['messages'] as $message) {
            $relative = relative($path);

            if (isChanged($changed, $relative, (int) ($message['line'] ?? 0))) {
                $mine[] = sprintf('%s:%d  %s%s', $relative, $message['line'] ?? 0, $message['message'], isset($message['identifier']) ? "  [{$message['identifier']}]" : '');
            } else {
                $old++;
            }
        }
    }

    foreach ($report['errors'] ?? [] as $error) {
        $mine[] = "geral  $error";
    }

    heading('PHPStan: nas suas linhas');
    echo $mine === [] ? "Nenhum erro.\n" : implode("\n", $mine) . "\n";

    if ($old > 0) {
        echo "\n$old erro(s) em linhas antigas dos arquivos que você mexeu. Lista completa: phpstan.json.\n";
    }

    writeSummary('phpstan', sprintf('%d nas suas linhas  (%d em código antigo)', count($mine), $old));

    return $mine === [] ? 0 : 1;
}

function rector(array $changed): int
{
    $report = json_decode((string) @file_get_contents(REPORTS . '/rector.json'), true);

    if (!is_array($report)) {
        writeSummary('rector', 'não rodou (veja rector.txt)');

        return 1;
    }

    $mine = [];
    $old  = 0;

    foreach ($report['file_diffs'] ?? [] as $fileDiff) {
        foreach (hunks($fileDiff['diff']) as [$start, $hunk]) {
            $touchesMine = false;
            $line        = $start;

            foreach (explode("\n", $hunk) as $row) {
                if (str_starts_with($row, '-') && isChanged($changed, $fileDiff['file'], $line)) {
                    $touchesMine = true;
                }

                if (!str_starts_with($row, '+')) {
                    $line++;
                }
            }

            if ($touchesMine) {
                $mine[] = "{$fileDiff['file']}:$start  (" . implode(', ', array_map(fn (string $rule): string => substr(strrchr('\\' . $rule, '\\'), 1), $fileDiff['applied_rectors'] ?? [])) . ")\n$hunk";
            } else {
                $old++;
            }
        }
    }

    heading('Rector: nas suas linhas');
    echo $mine === [] ? "Nenhuma sugestão.\n" : implode("\n", $mine) . "\n";

    if ($old > 0) {
        echo "\n$old sugestão(ões) em código antigo dos arquivos que você mexeu. Diff completo: rector.json.\n";
    }

    writeSummary('rector', sprintf('%d nas suas linhas  (%d em código antigo)', count($mine), $old));

    return 0;
}

/**
 * @return list<array{int, string}> linha inicial no arquivo atual e o trecho do diff
 */
function hunks(string $diff): array
{
    $hunks = [];

    foreach (preg_split('/^(?=@@ )/m', $diff) as $part) {
        if (preg_match('/^@@ -(\d+)/', $part, $match)) {
            $hunks[] = [(int) $match[1], rtrim(preg_replace('/^@@[^\n]*\n/', '', $part))];
        }
    }

    return $hunks;
}

function coverage(array $changed): int
{
    $clover = @simplexml_load_file(REPORTS . '/cobertura/clover.xml');

    if ($clover === false) {
        writeSummary('cobertura', 'não rodou (veja cobertura.txt)');

        return 1;
    }

    $rows      = [];
    $uncovered = [];
    $total     = 0;
    $hit       = 0;

    foreach ($clover->xpath('//file') as $file) {
        $relative = relative((string) $file['name']);

        if (!isset($changed[$relative])) {
            continue;
        }

        $fileTotal = 0;
        $fileHit   = 0;
        $missing   = [];

        foreach ($file->line as $line) {
            $number = (int) $line['num'];

            if ((string) $line['type'] === 'method' || !isChanged($changed, $relative, $number)) {
                continue;
            }

            $fileTotal++;

            if ((int) $line['count'] > 0) {
                $fileHit++;
            } else {
                $missing[] = $number;
            }
        }

        if ($fileTotal === 0) {
            continue;
        }

        $total += $fileTotal;
        $hit   += $fileHit;
        $rows[] = sprintf('%6.1f%%  %3d/%-3d  %s', $fileHit / $fileTotal * 100, $fileHit, $fileTotal, $relative);

        if ($missing !== []) {
            $uncovered[] = "$relative:" . implode(', ', $missing);
        }
    }

    heading('Cobertura das linhas que você mudou');
    echo $rows === [] ? "Nenhuma linha executável alterada em app/.\n" : implode("\n", $rows) . "\n";

    if ($uncovered !== []) {
        echo "\nLinhas alteradas que nenhum teste executa:\n" . implode("\n", $uncovered) . "\n";
    }

    writeSummary('cobertura', $total === 0
        ? 'nenhuma linha executável alterada'
        : sprintf('%.1f%% das linhas alteradas  (%d sem teste)', $hit / $total * 100, $total - $hit));

    return 0;
}

function infection(): int
{
    $report = json_decode((string) @file_get_contents(REPORTS . '/infection.json'), true);

    if (!is_array($report)) {
        writeSummary('infection', 'não rodou (veja infection.txt)');

        return 1;
    }

    $real       = [];
    $equivalent = [];

    foreach ($report['escaped'] ?? [] as $escaped) {
        $mutator = $escaped['mutator'];
        $where   = relative($mutator['originalFilePath']) . ':' . $mutator['originalStartLine'];
        $entry   = "$where  {$mutator['mutatorName']}\n" . rtrim(preg_replace('/^@@[^\n]*\n/m', '', $escaped['diff']));

        if (isProbablyEquivalent($mutator)) {
            $equivalent[] = $entry;
        } else {
            $real[] = $entry;
        }
    }

    heading('Infection: mutantes que escaparam');
    echo $real === [] ? "Nenhum.\n" : implode("\n\n", $real) . "\n";

    if ($equivalent !== []) {
        heading('Infection: provavelmente equivalentes (não dá para matar com teste)');
        echo implode("\n\n", $equivalent) . "\n";
    }

    $stats  = $report['stats'] ?? [];
    $killed = (int) ($stats['killedCount'] ?? 0);
    $total  = $killed + count($report['escaped'] ?? []) + (int) ($stats['notCoveredCount'] ?? 0) + (int) ($stats['timedOutCount'] ?? 0);
    $msi    = $total > 0 ? $killed / $total * 100 : 100;
    $msiWithoutEquivalent = $total - count($equivalent) > 0 ? $killed / ($total - count($equivalent)) * 100 : 100;

    writeSummary('infection', sprintf(
        'MSI %.0f%%  (%d escaparam%s)',
        $msi,
        count($real),
        $equivalent === [] ? '' : sprintf(', %d provavelmente equivalentes; sem eles, MSI %.0f%%', count($equivalent), $msiWithoutEquivalent),
    ));

    return 0;
}

/**
 * Tornar protegida uma action de controller não muda nada: o Laravel chama a action por dentro da classe.
 */
function isProbablyEquivalent(array $mutator): bool
{
    return $mutator['mutatorName'] === 'PublicVisibility'
        && str_contains($mutator['originalFilePath'], '/Http/Controllers/');
}

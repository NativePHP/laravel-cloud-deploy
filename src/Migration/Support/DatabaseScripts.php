<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Bash scripts, run on the Forge server, that copy a database to Cloud and
 * count rows on both sides.
 *
 * Passwords are put in shell variables at the top of the script and handed
 * to the clients through MYSQL_PWD / PGPASSWORD, so they never appear as
 * command-line arguments. The script itself travels over SSH on stdin.
 *
 * Connections are arrays with host, port, database, username and password.
 */
class DatabaseScripts
{
    /**
     * Check the dump and client tools exist on the server.
     */
    public static function tools(string $engine): string
    {
        $tools = $engine === 'pgsql' ? 'pg_dump psql' : 'mysqldump mysql';

        return "for tool in {$tools}; do command -v \"\$tool\" >/dev/null 2>&1 || echo \"missing:\$tool\"; done\n";
    }

    /**
     * Stream a dump of the source straight into the target.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: string}  $source
     * @param  array{host: string, port: int|string, database: string, username: string, password: string}  $target
     */
    public static function copy(string $engine, array $source, array $target): string
    {
        $script = self::variables($source, $target);

        if ($engine === 'pgsql') {
            return $script.<<<'BASH'
PGPASSWORD="$SRC_PASSWORD" pg_dump --no-owner --no-privileges --clean --if-exists \
    -h "$SRC_HOST" -p "$SRC_PORT" -U "$SRC_USER" -d "$SRC_DB" \
  | PGPASSWORD="$DST_PASSWORD" PGSSLMODE=require psql -q \
    -h "$DST_HOST" -p "$DST_PORT" -U "$DST_USER" -d "$DST_DB"

BASH;
        }

        // --set-gtid-purged only exists in MySQL's mysqldump, not MariaDB's.
        // DEFINER clauses are stripped because Cloud users can't create
        // routines and triggers owned by another user.
        return $script.<<<'BASH'
GTID=""
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then GTID="--set-gtid-purged=OFF"; fi
MYSQL_PWD="$SRC_PASSWORD" mysqldump --single-transaction --quick --routines --triggers --no-tablespaces $GTID \
    -h "$SRC_HOST" -P "$SRC_PORT" -u "$SRC_USER" "$SRC_DB" \
  | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' \
  | MYSQL_PWD="$DST_PASSWORD" mysql -h "$DST_HOST" -P "$DST_PORT" -u "$DST_USER" "$DST_DB"

BASH;
    }

    /**
     * Print "source|target <tab> table <tab> rows" for every table on both sides.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: string}  $source
     * @param  array{host: string, port: int|string, database: string, username: string, password: string}  $target
     */
    public static function count(string $engine, array $source, array $target): string
    {
        $script = self::variables($source, $target);

        if ($engine === 'pgsql') {
            return $script.<<<'BASH'
count_tables() {
    local label="$1" password="$2" host="$3" port="$4" user="$5" db="$6" sslmode="$7"
    local query="SELECT quote_ident(schemaname) || '.' || quote_ident(tablename) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')"
    for table in $(PGPASSWORD="$password" PGSSLMODE="$sslmode" psql -At -h "$host" -p "$port" -U "$user" -d "$db" -c "$query"); do
        rows=$(PGPASSWORD="$password" PGSSLMODE="$sslmode" psql -At -h "$host" -p "$port" -U "$user" -d "$db" -c "SELECT COUNT(*) FROM $table")
        printf '%s\t%s\t%s\n' "$label" "$table" "$rows"
    done
}
count_tables source "$SRC_PASSWORD" "$SRC_HOST" "$SRC_PORT" "$SRC_USER" "$SRC_DB" prefer
count_tables target "$DST_PASSWORD" "$DST_HOST" "$DST_PORT" "$DST_USER" "$DST_DB" require

BASH;
        }

        return $script.<<<'BASH'
count_tables() {
    local label="$1" password="$2" host="$3" port="$4" user="$5" db="$6"
    for table in $(MYSQL_PWD="$password" mysql -N -B -h "$host" -P "$port" -u "$user" -e "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" "$db" | cut -f1); do
        rows=$(MYSQL_PWD="$password" mysql -N -B -h "$host" -P "$port" -u "$user" -e "SELECT COUNT(*) FROM \`$table\`" "$db")
        printf '%s\t%s\t%s\n' "$label" "$table" "$rows"
    done
}
count_tables source "$SRC_PASSWORD" "$SRC_HOST" "$SRC_PORT" "$SRC_USER" "$SRC_DB"
count_tables target "$DST_PASSWORD" "$DST_HOST" "$DST_PORT" "$DST_USER" "$DST_DB"

BASH;
    }

    /**
     * Compare the output of count() and list the tables that differ.
     *
     * @return array{tables: int, mismatches: array<int, array{table: string, source: string, target: string}>}
     */
    public static function compareCounts(string $output): array
    {
        $counts = ['source' => [], 'target' => []];

        foreach (preg_split('/\r\n|\n/', trim($output)) ?: [] as $line) {
            $parts = explode("\t", $line);

            if (count($parts) === 3 && isset($counts[$parts[0]])) {
                $counts[$parts[0]][$parts[1]] = trim($parts[2]);
            }
        }

        $mismatches = [];

        foreach ($counts['source'] as $table => $rows) {
            $targetRows = $counts['target'][$table] ?? 'missing';

            if ($targetRows !== $rows) {
                $mismatches[] = ['table' => $table, 'source' => $rows, 'target' => $targetRows];
            }
        }

        return ['tables' => count($counts['source']), 'mismatches' => $mismatches];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    protected static function variables(array $source, array $target): string
    {
        $lines = [];

        foreach (['SRC' => $source, 'DST' => $target] as $prefix => $connection) {
            $lines[] = "{$prefix}_HOST=".escapeshellarg((string) $connection['host']);
            $lines[] = "{$prefix}_PORT=".escapeshellarg((string) $connection['port']);
            $lines[] = "{$prefix}_DB=".escapeshellarg((string) $connection['database']);
            $lines[] = "{$prefix}_USER=".escapeshellarg((string) $connection['username']);
            $lines[] = "{$prefix}_PASSWORD=".escapeshellarg((string) $connection['password']);
        }

        return implode("\n", $lines)."\n";
    }
}

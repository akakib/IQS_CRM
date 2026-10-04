<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OpsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups');
        File::deleteDirectory($this->dir);
        config([
            'backup.path' => $this->dir,
            'backup.connection' => 'mysql_fake',
            'database.connections.mysql_fake' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'iqs', 'username' => 'u', 'password' => 'secret-pw',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fakeDump(): void
    {
        Process::fake(function (PendingProcess $process) {
            $file = collect($process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));
            file_put_contents(substr($file, 14), "CREATE TABLE x (id int);\n");

            return Process::result();
        });
    }

    public function test_backup_writes_gzip_and_keeps_password_off_the_command_line(): void
    {
        $this->fakeDump();

        $this->artisan('backup:database')->assertSuccessful();

        $files = File::glob($this->dir.'/iqs-*.sql.gz');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('CREATE TABLE', gzdecode(file_get_contents($files[0])));
        $this->assertEmpty(File::glob($this->dir.'/*.sql'));

        Process::assertRan(fn (PendingProcess $p) => ! in_array('secret-pw', $p->command, true)
            && ($p->environment['MYSQL_PWD'] ?? null) === 'secret-pw');
    }

    public function test_backup_keeps_only_the_newest_fourteen(): void
    {
        $this->fakeDump();
        File::ensureDirectoryExists($this->dir);
        foreach (range(1, 16) as $i) {
            file_put_contents(sprintf('%s/iqs-202601%02d-030000.sql.gz', $this->dir, $i), 'old');
        }

        $this->artisan('backup:database')->assertSuccessful();

        $files = collect(File::glob($this->dir.'/iqs-*.sql.gz'))->map(fn ($f) => basename($f));
        $this->assertCount(14, $files);
        $this->assertFalse($files->contains('iqs-20260101-030000.sql.gz'));
    }

    public function test_failed_dump_is_reported(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied', exitCode: 2));

        $this->artisan('backup:database')->assertFailed();
        $this->assertSame('Access denied', cache('backup:last_error')['message']);
    }

    public function test_scheduler_has_queue_backup_and_cleanup(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->join(' | ');

        $this->assertStringContainsString('queue:work --stop-when-empty', $commands);
        $this->assertStringContainsString('backup:database', $commands);
        $this->assertStringContainsString('permissions:prune-expired', $commands);
    }

    public function test_health_page_is_owner_only(): void
    {

        $this->actingAs($this->owner())->get('/health')->assertOk()->assertSee('Queue backlog')->assertSee('fake (no real parcels)');
        $this->actingAs(User::factory()->create())->get('/health')->assertForbidden();
    }
}

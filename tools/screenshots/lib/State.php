<?php

/**
 * Keeps a test site as it was found: copies its state (folders, a MySQL
 * database or a SQLite file) aside before anything is seeded, and puts it
 * back afterwards, even when a shot fails. A copy left behind by a run that
 * was killed is put back at the start of the next one.
 */
class State
{
    /** @var array<int, string> */
    private array $paths = [];

    private ?string $mysql = null;

    private ?string $sqlite = null;

    private string $dir;

    public function __construct(private string $root, string $name)
    {
        $this->dir = sys_get_temp_dir().'/ghostwriter-shots/'.$name;
    }

    /**
     * @param  array<int, string>  $paths  Folders or files, relative to the site.
     */
    public function files(array $paths): static
    {
        $this->paths = $paths;

        return $this;
    }

    public function mysql(string $database): static
    {
        $this->mysql = $database;

        return $this;
    }

    public function sqlite(string $file): static
    {
        $this->sqlite = $file;

        return $this;
    }

    public function scratch(): string
    {
        return $this->dir;
    }

    public function save(): void
    {
        if (is_file($this->dir.'/SAVED')) {
            echo "A run that didn't finish left its copy behind; putting the site back first.\n";
            $this->restore();
        }

        exec('rm -rf '.escapeshellarg($this->dir));
        mkdir($this->dir, 0777, true);

        $existing = array_values(array_filter($this->paths, fn ($path) => file_exists($this->root.'/'.$path)));
        file_put_contents($this->dir.'/paths.json', json_encode(['all' => $this->paths, 'existing' => $existing]));

        if ($existing !== []) {
            $this->run('tar -czf '.escapeshellarg($this->dir.'/files.tgz').' -C '.escapeshellarg($this->root).' '.implode(' ', array_map('escapeshellarg', $existing)));
        }

        if ($this->mysql) {
            $this->run('mysqldump -uroot -h127.0.0.1 --single-transaction --skip-comments --add-drop-table '.escapeshellarg($this->mysql).' > '.escapeshellarg($this->dir.'/database.sql'));
        }

        if ($this->sqlite) {
            $this->run('sqlite3 '.escapeshellarg($this->root.'/'.$this->sqlite).' '.escapeshellarg('.backup '.$this->dir.'/database.sqlite'));
        }

        file_put_contents($this->dir.'/SAVED', date('c'));
    }

    public function restore(): void
    {
        if (! is_file($this->dir.'/SAVED')) {
            return;
        }

        $paths = json_decode((string) file_get_contents($this->dir.'/paths.json'), true);

        foreach ($paths['all'] as $path) {
            if ($path !== '' && ! str_contains($path, '..')) {
                $this->run('rm -rf '.escapeshellarg($this->root.'/'.$path));
            }
        }

        if (is_file($this->dir.'/files.tgz')) {
            $this->run('tar -xzf '.escapeshellarg($this->dir.'/files.tgz').' -C '.escapeshellarg($this->root));
        }

        if ($this->mysql && is_file($this->dir.'/database.sql')) {
            // Tables made since the copy are dropped first, so the database matches it exactly.
            $tables = [];
            exec('mysql -uroot -h127.0.0.1 -N -e '.escapeshellarg('SHOW TABLES').' '.escapeshellarg($this->mysql), $tables);
            $saved = (string) file_get_contents($this->dir.'/database.sql');

            foreach ($tables as $table) {
                if (! str_contains($saved, "CREATE TABLE `{$table}`")) {
                    $this->run('mysql -uroot -h127.0.0.1 -e '.escapeshellarg("SET FOREIGN_KEY_CHECKS=0; DROP TABLE `{$table}`").' '.escapeshellarg($this->mysql));
                }
            }

            $this->run('mysql -uroot -h127.0.0.1 '.escapeshellarg($this->mysql).' < '.escapeshellarg($this->dir.'/database.sql'));
        }

        if ($this->sqlite && is_file($this->dir.'/database.sqlite')) {
            $this->run('sqlite3 '.escapeshellarg($this->root.'/'.$this->sqlite).' '.escapeshellarg('.restore '.$this->dir.'/database.sqlite'));
        }

        @unlink($this->dir.'/SAVED');
        echo "Put the site back as it was.\n";
    }

    private function run(string $command): void
    {
        exec($command.' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new RuntimeException("Failed: {$command}\n".implode("\n", $output));
        }
    }
}

<?php

namespace App\Services;

use App\Models\Execution;
use App\Models\WorkflowFile;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Files that runs make or receive. The bytes go on the workflow files disk;
 * between nodes a file travels as a small reference,
 *
 *     {"kind": "file", "id": "…", "name": "logo.png", "mime": "image/png", "size": 48213}
 *
 * which a node that takes files turns back into the file with find(). A
 * file is only ever found from its own workspace. Files are deleted with
 * their run's logs; ones saved for a run that never started are deleted
 * after a day.
 */
class WorkflowFiles
{
    /**
     * The workspace whose files text() and base64() may read while a step's
     * inputs are rendered.
     */
    protected ?int $scope = null;

    public static function isReference(mixed $value): bool
    {
        return is_array($value)
            && ($value['kind'] ?? null) === 'file'
            && is_string($value['id'] ?? null)
            && Str::isUuid($value['id']);
    }

    /**
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     */
    public static function reference(WorkflowFile $file): array
    {
        return ['kind' => 'file', 'id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'size' => $file->size];
    }

    /**
     * Save bytes as a file of the workspace, for $execution or, when the run
     * has not started yet, for the run claim() later hands it to.
     *
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     *
     * @throws RuntimeException when the file is larger than allowed
     */
    public function store(Workspace $workspace, ?Execution $execution, string $contents, string $name, ?string $mime = null): array
    {
        $this->guardSize(strlen($contents));

        $file = $this->newFile($workspace, $execution, $name, $mime ?: $this->guessMime($name, $contents), strlen($contents), hash('sha256', $contents));
        Storage::disk($file->disk)->put($file->path, $contents);
        $file->save();

        return static::reference($file);
    }

    /**
     * Save a file already on local disk, such as a download or an upload,
     * without reading it into memory.
     *
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     */
    public function storePath(Workspace $workspace, ?Execution $execution, string $path, string $name, ?string $mime = null): array
    {
        $size = (int) filesize($path);
        $this->guardSize($size);

        $file = $this->newFile($workspace, $execution, $name, $mime ?: $this->guessMime($name, (string) file_get_contents($path, length: 2048)), $size, (string) hash_file('sha256', $path));
        $stream = fopen($path, 'rb');

        try {
            Storage::disk($file->disk)->writeStream($file->path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $file->save();

        return static::reference($file);
    }

    /**
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     */
    public function storeUpload(Workspace $workspace, ?Execution $execution, UploadedFile $upload): array
    {
        return $this->storePath($workspace, $execution, $upload->getRealPath(), $upload->getClientOriginalName() ?: 'upload', $upload->getMimeType());
    }

    /**
     * The file a reference points to, if it belongs to the workspace.
     */
    public function find(Workspace|int $workspace, mixed $reference): ?WorkflowFile
    {
        if (! static::isReference($reference)) {
            return null;
        }

        return WorkflowFile::query()
            ->whereKey($reference['id'])
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->first();
    }

    /**
     * @return resource
     */
    public function readStream(WorkflowFile $file)
    {
        return Storage::disk($file->disk)->readStream($file->path) ?? throw new RuntimeException(__('run_files.file_is_missing', ['name' => $file->name]));
    }

    /**
     * A file's bytes, up to the placeholder helpers' limit.
     *
     * @throws RuntimeException when the file is too large to read into a run's data
     */
    public function contents(WorkflowFile $file): string
    {
        $limit = (int) config('workflows.files.text_max_bytes');

        if ($file->size > $limit) {
            throw new RuntimeException(__('run_files.too_large_to_read', ['name' => $file->name, 'limit' => $this->megabytes($limit)]));
        }

        return (string) Storage::disk($file->disk)->get($file->path);
    }

    public function localPath(WorkflowFile $file): ?string
    {
        $disk = Storage::disk($file->disk);

        return method_exists($disk, 'path') && config("filesystems.disks.{$file->disk}.driver") === 'local' ? $disk->path($file->path) : null;
    }

    /**
     * While a step's inputs render, text() and base64() may read files of
     * this workspace only.
     */
    public function scopeTo(?Workspace $workspace): void
    {
        $this->scope = $workspace?->id;
    }

    /**
     * A file in the current scope, for the placeholder helpers.
     *
     * @throws RuntimeException when $reference is not a file of the scope
     */
    public function inScope(mixed $reference): WorkflowFile
    {
        if (! static::isReference($reference)) {
            throw new RuntimeException(__('run_files.not_a_file'));
        }

        return ($this->scope === null ? null : $this->find($this->scope, $reference))
            ?? throw new RuntimeException(__('run_files.file_not_found'));
    }

    /**
     * Hand files saved before a run started, such as form uploads, to it.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function claim(Execution $execution, array $input): void
    {
        $ids = array_column(static::referencesIn($input), 'id');

        if ($ids !== []) {
            WorkflowFile::query()
                ->whereKey($ids)
                ->where('workspace_id', $execution->workspace_id)
                ->whereNull('execution_id')
                ->update(['execution_id' => $execution->id]);
        }
    }

    /**
     * The file references anywhere in a value, such as a step's output.
     *
     * @return list<array{kind: 'file', id: string, name: string, mime: string, size: int}>
     */
    public static function referencesIn(mixed $value): array
    {
        if (static::isReference($value)) {
            return [$value];
        }

        return is_array($value) ? array_merge([], ...array_map(static::referencesIn(...), array_values($value))) : [];
    }

    /**
     * Delete the files of these runs, bytes and rows.
     *
     * @param  iterable<string>  $executionIds
     */
    public function deleteForExecutions(iterable $executionIds): void
    {
        WorkflowFile::query()->whereIn('execution_id', collect($executionIds)->all())->chunkById(200, fn ($files) => $this->deleteFiles($files));
    }

    public function deleteForWorkspace(Workspace $workspace): void
    {
        WorkflowFile::query()->where('workspace_id', $workspace->id)->chunkById(200, fn ($files) => $this->deleteFiles($files));
    }

    /**
     * Delete files saved for a run that never started, such as uploads of a
     * form whose run failed to start.
     */
    public function deleteAbandoned(): int
    {
        $count = 0;

        WorkflowFile::query()
            ->whereNull('execution_id')
            ->where('created_at', '<', now()->subDay())
            ->chunkById(200, function ($files) use (&$count) {
                $count += $files->count();
                $this->deleteFiles($files);
            });

        return $count;
    }

    /**
     * @param  iterable<WorkflowFile>  $files
     */
    protected function deleteFiles(iterable $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
            $file->delete();
        }
    }

    protected function newFile(Workspace $workspace, ?Execution $execution, string $name, string $mime, int $size, string $sha256): WorkflowFile
    {
        $file = new WorkflowFile([
            'workspace_id' => $workspace->id,
            'execution_id' => $execution?->id,
            'name' => $this->safeName($name),
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'disk' => (string) config('workflows.files.disk'),
        ]);
        $file->id = (string) Str::uuid7();
        $file->path = "{$workspace->id}/{$file->id}";

        return $file;
    }

    protected function guardSize(int $size): void
    {
        $limit = (int) config('workflows.files.max_bytes');

        if ($size > $limit) {
            throw new RuntimeException(__('run_files.too_large', ['limit' => $this->megabytes($limit)]));
        }
    }

    /**
     * A file name without folders or control characters, at most 200 bytes.
     */
    protected function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))));

        return Str::limit($name === '' || $name === '.' || $name === '..' ? 'file' : $name, 200, '');
    }

    protected function guessMime(string $name, string $contents): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $byExtension = $extension === '' ? null : (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null);

        if ($byExtension !== null) {
            return $byExtension;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    protected function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MB';
    }
}

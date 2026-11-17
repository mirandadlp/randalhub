<?php

namespace App\Services\Resume;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resumes are stored on the private (non-web-accessible) disk, encrypted at rest with APP_KEY.
 */
class ResumeStorage
{
    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public function __construct(private ResumeTextExtractor $extractor) {}

    public function store(User $user, UploadedFile $file, ?string $label = null, bool $master = false): Resume
    {
        $bytes = file_get_contents($file->getRealPath());
        $mime = $this->detectMime($bytes, $file);
        $sha = hash('sha256', $bytes);

        $existing = Resume::where('user_id', $user->id)->where('sha256', $sha)->first();
        if ($existing) {
            return $existing;
        }

        $path = 'resumes/'.$user->id.'/'.Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($bytes)));

        $resume = Resume::create([
            'user_id' => $user->id,
            'label' => $label ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'storage_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'sha256' => $sha,
            'is_master' => $master || ! Resume::where('user_id', $user->id)->exists(),
            'parse_status' => 'pending',
        ]);

        if ($resume->is_master) {
            Resume::where('user_id', $user->id)->where('id', '!=', $resume->id)->update(['is_master' => false]);
        }

        try {
            $resume->update(['extracted_text' => $this->extractor->extract($bytes, $mime)]);
        } catch (\Throwable $e) {
            $resume->update(['parse_status' => 'failed', 'parse_error' => 'Text extraction failed: '.mb_substr($e->getMessage(), 0, 500)]);
        }

        return $resume;
    }

    public function contents(Resume $resume): string
    {
        return base64_decode(Crypt::decryptString(Storage::disk('local')->get($resume->storage_path)));
    }

    /** Writes a decrypted temporary copy (used by the autofill browser to upload the file). */
    public function temporaryCopy(Resume $resume): string
    {
        $dir = storage_path('app/private/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $ext = self::MIME_TYPES[$resume->mime_type] ?? 'pdf';
        $name = Str::slug(pathinfo($resume->original_filename, PATHINFO_FILENAME)) ?: 'resume';
        $path = $dir.'/'.$name.'-'.Str::random(8).'.'.$ext;
        file_put_contents($path, $this->contents($resume));
        chmod($path, 0600);

        return $path;
    }

    public function delete(Resume $resume): void
    {
        Storage::disk('local')->delete($resume->storage_path);
        $resume->delete();
    }

    private function detectMime(string $bytes, UploadedFile $file): string
    {
        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        // Never trust the client-declared type: anything without PDF/DOCX magic bytes is rejected.
        return 'application/octet-stream';
    }
}

<?php

namespace App\Services\Document;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ảnh / PDF phiếu khám được mã hoá AES-256 trước khi ghi đĩa, tên file ngẫu nhiên,
 * nằm ngoài thư mục public. Nếu có DOCUMENT_ENCRYPTION_KEY thì dùng khoá riêng
 * (lộ APP_KEY hay CSDL cũng không đọc được ảnh); file cũ mã hoá bằng APP_KEY vẫn đọc được.
 */
final class DocumentEncryptionService
{
    private Filesystem $disk;

    private ?Encrypter $dedicated = null;

    public function __construct()
    {
        $this->disk = Storage::disk(config('filesystems.documents_disk', config('filesystems.default', 'local')));

        $key = (string) config('filesystems.documents_key', '');
        if ($key !== '') {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
            $this->dedicated = new Encrypter($raw, 'AES-256-CBC');
        }
    }

    /**
     * Lưu file đã mã hóa, trả về đường dẫn tương đối trên disk.
     */
    public function storeEncrypted(\Illuminate\Http\UploadedFile $file): string
    {
        return $this->storeEncryptedContent((string) $file->get(), $file->guessExtension() ?: 'bin');
    }

    /**
     * Mã hóa và lưu nội dung có sẵn.
     */
    public function storeEncryptedContent(string $content, string $extension): string
    {
        $relativePath = $this->generateRandomPath($extension);
        $this->disk->put($relativePath, $this->encrypt($content));

        return $relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return $this->disk->exists($relativePath);
    }

    /**
     * Giải mã và trả về nội dung nhị phân của file.
     */
    public function decryptContent(string $relativePath): string
    {
        if (! $this->disk->exists($relativePath)) {
            throw new \RuntimeException('File không tồn tại.');
        }

        $encrypted = (string) $this->disk->get($relativePath);

        if ($this->dedicated !== null) {
            try {
                return $this->dedicated->decryptString($encrypted);
            } catch (DecryptException) {
                // File cũ mã hoá bằng APP_KEY.
            }
        }

        return Crypt::decryptString($encrypted);
    }

    /**
     * Xoá file đã lưu.
     */
    public function delete(string $relativePath): void
    {
        if ($this->disk->exists($relativePath)) {
            $this->disk->delete($relativePath);
        }
    }

    private function encrypt(string $content): string
    {
        return $this->dedicated?->encryptString($content) ?? Crypt::encryptString($content);
    }

    private function generateRandomPath(string $extension): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return 'documents/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension.'.enc';
    }
}

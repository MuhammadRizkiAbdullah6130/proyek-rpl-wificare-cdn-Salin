<?php
/**
 * Exception validasi formulir.
 *
 * Membawa daftar pesan kesalahan per field sehingga controller dapat
 * menampilkannya kembali ke pengguna tanpa membocorkan detail teknis.
 */

declare(strict_types=1);

final class ValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors Pesan kesalahan per nama field.
     */
    public function __construct(array $errors, string $message = 'Data yang dikirim tidak valid.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Gabungan pesan kesalahan untuk ditampilkan sebagai satu callout.
     */
    public function errorsAsString(): string
    {
        return implode(' ', array_values($this->errors));
    }
}

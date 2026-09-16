<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * Which products answered, and when.
 *
 * A dashboard composed from three live products will sometimes be composed from
 * two. This is how the screen says so, per source, instead of quietly rendering
 * a smaller number. "We could not ask" and "we asked and the answer was zero"
 * are different facts and are never merged here.
 */
final class Sources
{
    public const READY       = 'ready';
    public const STALE       = 'stale';
    public const UNAVAILABLE = 'unavailable';

    /** @var array<string, array<string, mixed>> */
    private array $sources = [];

    public function __construct()
    {
        // Purchases is this application's own database. If it were unreachable
        // the request would already have failed, so it starts ready.
        $this->ready('purchases', 'Purchases');
    }

    public function ready(string $id, string $label, ?string $asOf = null): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::READY,
            'status_label' => 'Live',
            'as_of'        => $asOf ?? gmdate('c'),
            'message'      => null,
        ];

        return $this;
    }

    public function unavailable(string $id, string $label, string $message): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::UNAVAILABLE,
            'status_label' => 'Unavailable',
            'as_of'        => null,
            'message'      => $message,
        ];

        return $this;
    }

    public function stale(string $id, string $label, string $asOf, string $message): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::STALE,
            'status_label' => 'Stale',
            'as_of'        => $asOf,
            'message'      => $message,
        ];

        return $this;
    }

    /** Not asked at all — a permission the user does not hold, or a panel not on this screen. */
    public function notRequested(string $id, string $label, string $message): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::UNAVAILABLE,
            'status_label' => 'Not requested',
            'as_of'        => null,
            'message'      => $message,
        ];

        return $this;
    }

    public function isReady(string $id): bool
    {
        return ($this->sources[$id]['status'] ?? null) === self::READY;
    }

    /**
     * Record the outcome of an ApiClient call in one line.
     *
     * @param array{ok: bool, status: int, error?: ?string} $result
     */
    public function record(string $id, string $label, array $result, string $unavailableMessage): bool
    {
        if ($result['ok'] ?? false) {
            $this->ready($id, $label);

            return true;
        }

        $detail = match (true) {
            ($result['status'] ?? 0) === 403 => ' You do not have access to that data in ' . $label . '.',
            ($result['status'] ?? 0) === 401 => ' ' . $label . ' did not accept this session.',
            default                          => '',
        };

        $this->unavailable($id, $label, $unavailableMessage . $detail);

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_values($this->sources);
    }
}

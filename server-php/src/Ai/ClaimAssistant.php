<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Domain\ReturnClaimService;

/**
 * The AI help offered beside the New Claim form.
 *
 * FIVE NAMED JOBS AND NOTHING ELSE. The screen sends an intent from this list
 * and the draft the buyer has typed so far; it cannot send a prompt. That is
 * the point of the class: "ask the model anything the browser says" is an
 * endpoint that can be pointed at whatever the caller likes, and this one
 * cannot be.
 *
 * WHAT IT IS GIVEN. Only the draft in front of the user — the supplier they
 * chose, the kind of problem, the references, the line totals. No ledger, no
 * other claim, no other company. A claim being typed is already on the user's
 * screen, so nothing leaves this server that the person asking cannot already
 * see.
 *
 * WHAT IT MAY RETURN. Prose, or one of OUR claim kinds. Never a decision: the
 * suggestion is shown beside the field and the user accepts or discards it, and
 * nothing here writes to a claim.
 *
 * With no model configured every intent answers `available: false` with the
 * reason, and the screen says so. That is the normal state of a deployment that
 * has not set a key, not an error.
 */
final class ClaimAssistant
{
    public const INTENTS = [
        'draft_description'   => 'Draft the description from the facts entered so far',
        'improve_description' => 'Rewrite the description the buyer has written',
        'suggest_type'        => 'Say which kind of claim this looks like',
        'suggest_documents'   => 'List the documents worth attaching',
        'summarise'           => 'Summarise the claim in a few sentences',
    ];

    /** Nothing longer than this is sent, whatever the browser posts. */
    private const FIELD_MAX = 1200;

    /**
     * @param array<string, mixed> $draft the claim as typed so far
     * @return array{available: bool, intent: string, kind: ?string, text: ?string, reason: ?string}
     */
    public static function run(string $intent, array $draft): array
    {
        if (!array_key_exists($intent, self::INTENTS)) {
            return self::unavailable($intent, 'That is not something the assistant can be asked to do.');
        }

        if (!AiClient::isConfigured()) {
            return self::unavailable($intent, (string) AiClient::status()['reason']);
        }

        $grounding = self::grounding($draft);

        if ($intent === 'suggest_type') {
            return self::suggestKind($grounding);
        }

        $result = AiClient::narrate(self::task($intent), $grounding);

        return [
            'available' => $result['ok'],
            'intent'    => $intent,
            'kind'      => null,
            'text'      => $result['ok'] ? trim((string) $result['text']) : null,
            'reason'    => $result['ok'] ? null : $result['error'],
        ];
    }

    /**
     * The model chooses between the kinds this product already has. It answers
     * with a label from our list or with nothing — it never names a kind the
     * API would then refuse.
     *
     * @param array<string, mixed> $grounding
     * @return array{available: bool, intent: string, kind: ?string, text: ?string, reason: ?string}
     */
    private static function suggestKind(array $grounding): array
    {
        $intents = [];
        foreach (ReturnClaimService::CLAIM_KINDS as $value => $label) {
            $intents[] = ['id' => $value, 'description' => $label];
        }

        $question = json_encode($grounding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $result = AiClient::classify(is_string($question) ? $question : '', $intents);

        if (!$result['ok']) {
            return self::unavailable('suggest_type', $result['error'] ?? 'The assistant could not be reached.');
        }

        $kind = $result['intent'];

        return [
            'available' => true,
            'intent'    => 'suggest_type',
            'kind'      => $kind,
            'text'      => $kind === null
                ? 'There is not enough in the claim yet to tell which kind it is.'
                : ReturnClaimService::CLAIM_KINDS[$kind],
            'reason'    => null,
        ];
    }

    private static function task(string $intent): string
    {
        return match ($intent) {
            'draft_description' => 'Write the body of a supplier claim: what happened, what is being claimed, and what '
                . 'resolution is sought. Address it to the supplier, professionally and without accusation. '
                . 'Use only the facts given.',
            'improve_description' => 'Rewrite the buyer\'s description so it is clearer and more professional. '
                . 'Keep every fact and every figure exactly as written. Add nothing that is not there.',
            'suggest_documents' => 'List the supporting documents this claim should carry, as a short sentence each, '
                . 'based on the kind of claim and the references given.',
            default => 'Summarise this supplier claim for the buyer who raised it.',
        };
    }

    /**
     * The draft, whitelisted and clipped.
     *
     * Built key by key rather than passed through, so a field the browser
     * invents does not reach the prompt.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private static function grounding(array $draft): array
    {
        $out = [
            'supplier'             => self::field($draft['supplier_name'] ?? null),
            'claim_kind'           => self::field($draft['claim_kind'] ?? null),
            'claim_date'           => self::field($draft['claim_date'] ?? null),
            'subject'              => self::field($draft['subject'] ?? null),
            'description'          => self::field($draft['description'] ?? null),
            'requested_resolution' => self::field($draft['requested_resolution'] ?? null),
            'total_claim_amount'   => self::field($draft['total_amount'] ?? null),
            'references'           => [],
            'lines'                => [],
        ];

        foreach (['purchase_order', 'purchase_bill', 'delivery', 'return'] as $key) {
            $value = self::field($draft['references'][$key] ?? null);
            if ($value !== null) {
                $out['references'][$key] = $value;
            }
        }

        $lines = is_array($draft['lines'] ?? null) ? $draft['lines'] : [];
        foreach (array_slice($lines, 0, 25) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $out['lines'][] = [
                'item'         => self::field($line['description'] ?? null),
                'ordered_qty'  => self::field($line['ordered_qty'] ?? null),
                'received_qty' => self::field($line['received_qty'] ?? null),
                'claim_qty'    => self::field($line['claim_qty'] ?? null),
                'rate'         => self::field($line['rate'] ?? null),
                'claim_amount' => self::field($line['claim_amount'] ?? null),
                'reason'       => self::field($line['reason'] ?? null),
            ];
        }

        return $out;
    }

    private static function field(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, self::FIELD_MAX);
    }

    /** @return array{available: bool, intent: string, kind: ?string, text: ?string, reason: ?string} */
    private static function unavailable(string $intent, string $reason): array
    {
        return ['available' => false, 'intent' => $intent, 'kind' => null, 'text' => null, 'reason' => $reason];
    }
}

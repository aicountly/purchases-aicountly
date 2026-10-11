<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Domain\BooksVoucherEvents;
use Aicountly\Api\Http;
use Aicountly\Api\ServiceKeys;
use Aicountly\Api\Support\BooksEventSignature;

/**
 * POST /api/v1/integration/books/voucher-events — Books tells us it cancelled or edited a voucher
 * it holds for one of our bills (E8-BK-POST A16). Books only: the X-Service-Key must resolve to `books`
 * (SERVICE_KEYS=books:<key>) AND the body must carry Books' signature (BooksEventSignature). The
 * company is the event's, checked against the key's company scope; the document is found by the
 * Books voucher id WE stored for that company, never by an id taken on trust.
 *
 * Answers {data: {status: applied|ignored, reference, reason?}} — Books records a delivery only
 * with a reference, and redelivers (same event id) until it gets one.
 */
final class BooksEventsController extends Controller
{
    public static function voucherEvents(): void
    {
        $auth = Auth::resolve();
        if ($auth === null || !$auth->isService() || $auth->sourceApp !== 'books') {
            Http::unauthorized('Only Smart Books may send voucher events, with its service key.');
        }
        $raw = (string) file_get_contents('php://input');
        $verdict = BooksEventSignature::verify(Http::header('X-Books-Signature'), $raw);
        if (!$verdict['ok']) {
            if (($verdict['code'] ?? '') === 'not_configured') {
                Http::error(503, 'receiver_not_configured', 'BOOKS_EVENTS_SIGNING_SECRET is not set on this deployment.');
            }
            Http::error(401, (string) $verdict['code'], 'The voucher event signature is not valid.');
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            Http::validationFailed('A JSON event body is required.');
        }
        $eventId = trim((string) ($event['event_id'] ?? ''));
        $cmpId = (int) ($event['cmp_id'] ?? 0);
        if ($eventId === '' || strlen($eventId) > 64 || $eventId !== Http::header('X-Books-Event-Id')) {
            Http::validationFailed('event_id is required and must match X-Books-Event-Id.', ['field' => 'event_id']);
        }
        if ($cmpId <= 0 || (int) ($event['vch_txn_id'] ?? 0) <= 0 || !in_array((string) ($event['event_type'] ?? ''), ['voucher.cancelled', 'voucher.revised'], true)) {
            Http::validationFailed('cmp_id, vch_txn_id and event_type (voucher.cancelled | voucher.revised) are required.');
        }
        // Books keeps every company's accounts in this deployment, and the event is signed with the
        // secret only Books holds; it touches only a bill WE stored against that company and voucher.
        // So the company binding narrows Books when it is configured, and is not required for this
        // one endpoint (every other service route still needs SERVICE_KEY_COMPANIES).
        if (ServiceKeys::hasCompanyBinding('books') && !ServiceKeys::allowsCompany('books', $cmpId)) {
            Http::forbidden('The books service key is not allowed to act for this company.');
        }

        Http::data(BooksVoucherEvents::apply($event, $auth));
    }
}

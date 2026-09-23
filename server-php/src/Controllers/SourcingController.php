<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\SourcingService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SourcingController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.view');

        $params = Http::listParams(['rfq_date', 'rfq_no', 'status', 'response_deadline', 'created_at'], 'rfq_date');

        // `created_by=me` rather than a uuid on the query string: the browser
        // should not have to know its own uuid to ask for its own enquiries,
        // and a uuid in a shared link would filter to whoever sent it.
        $createdBy = Http::param('created_by');
        if ($createdBy === 'me') {
            $createdBy = $auth->uuid;
        }

        $result = (new SourcingService($ctx, $auth))->searchRfqs([
            'status'              => Http::param('status'),
            'q'                   => $params['q'],
            'from'                => Http::param('from'),
            'to'                  => Http::param('to'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'created_by'          => $createdBy,
            'quotes'              => Http::param('quotes'),
            'deadline'            => Http::param('deadline'),
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        // The lifecycle tabs are drawn from `status_counts`, which is counted
        // under the same search and dates as the page itself. Sending the page
        // without them would mean a second round trip for every keystroke.
        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], [
            'status_counts' => $result['status_counts'],
        ]);
    }

    /**
     * The figures above the list: pipeline counts, quoted value, the spread
     * between competing quotations.
     *
     * Separate from the list because it does not change when somebody sorts a
     * column or turns a page, and because it answers for the whole financial
     * year rather than for one page of it.
     */
    public static function summary(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data((new SourcingService($ctx, $auth))->summary());
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.view');

        $rfq = (new SourcingService($ctx, $auth))->findRfq((int) $id);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }

        Http::data($rfq);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->createRfq(Http::body()), 201);
    }

    public static function issue(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->issueRfq((int) $id));
    }

    public static function invite(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.create');

        $supplierId = Http::intParam('supplier_account_id');
        if ($supplierId === null) {
            Http::validationFailed('Say which supplier to invite.', ['field' => 'supplier_account_id']);
        }

        $service = new SourcingService($ctx, $auth);
        $service->inviteSupplier((int) $id, $supplierId);

        Http::data($service->findRfq((int) $id));
    }

    public static function recordQuote(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->recordQuote((int) $id, Http::body()), 201);
    }

    /** The comparative statement — quoted rates beside estimated landed totals. */
    public static function compare(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->compare((int) $id));
    }

    public static function award(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->award((int) $id, Http::body()));
    }
}

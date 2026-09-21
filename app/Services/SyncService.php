<?php

namespace App\Services;

use App\Enums\Role;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Shop;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applies events a device recorded while offline. The rules it enforces:
 *
 *  - Pushing the same event again never applies it twice.
 *  - Each event stands alone: one failing sale never blocks the ones after it.
 *  - Nothing is silently changed. If the server can't apply an event exactly
 *    as the device recorded it, the event is reported back as "conflict" or
 *    "rejected" with the reason, and nothing is written for it.
 *  - A person can only sync their own sales; an owner/manager can sync (and
 *    approve price changes on) anyone's in the shop, and the sale stays
 *    attributed to the person who actually made it.
 */
class SyncService
{
    public function __construct(private SaleService $sales) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public function push(Shop $shop, User $pusher, Role $role, string $deviceId, array $events): array
    {
        return array_map(
            fn (array $event) => $this->processEvent($shop, $pusher, $role, $deviceId, $event),
            $events,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function processEvent(Shop $shop, User $pusher, Role $role, string $deviceId, array $event): array
    {
        $localId = (string) $event['local_event_id'];
        $payload = $event['payload'] ?? [];
        $hash = hash('sha256', json_encode($payload));
        $isManager = in_array($role, [Role::Owner, Role::Manager], true);

        $record = SyncEvent::where('shop_id', $shop->id)
            ->where('device_id', $deviceId)
            ->where('local_event_id', $localId)
            ->first();

        if ($record?->status === 'processed') {
            if ($record->payload_hash !== $hash) {
                return $this->outcome($localId, 'conflict', [
                    'message' => 'This event id was already used for a different sale.',
                ]);
            }

            return $this->outcome($localId, 'processed', array_merge($record->result ?? [], ['duplicate' => true]));
        }

        $cashier = $this->resolveCashier($shop, $pusher, $isManager, $event['cashier_id'] ?? null);

        if (! $cashier) {
            return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'rejected', [
                'message' => 'Only a manager can sync another person\'s sales, and they must belong to this shop.',
            ]));
        }

        $validator = Validator::make($payload, (new StoreSaleRequest)->rules());

        if ($validator->fails()) {
            return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'rejected', [
                'message' => 'The sale details were not valid.',
                'errors' => $validator->errors()->toArray(),
            ]));
        }

        $approved = (bool) ($event['accept_agreed_prices'] ?? false);

        if ($approved && ! $isManager) {
            return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'conflict', [
                'message' => 'A manager must approve recording this sale at the price charged.',
            ]));
        }

        $data = $validator->validated();
        $data['idempotency_key'] = $data['idempotency_key'] ?? "sync-{$deviceId}-{$localId}";
        $data['synced_at'] = now();
        $data['client_created_at'] = $data['client_created_at'] ?? $event['client_created_at'] ?? null;
        $data['allow_price_override'] = $approved;

        if (isset($payload['expected_total'])) {
            $data['expected_total'] = (int) $payload['expected_total'];
        }

        try {
            $sale = $this->sales->create($shop, $cashier, $data);
        } catch (PriceChanged $e) {
            return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'conflict', [
                'message' => 'Prices changed since this sale was made offline.',
                'expected_total' => $e->expected,
                'server_total' => $e->serverTotal,
            ]));
        } catch (ValidationException $e) {
            return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'rejected', [
                'message' => collect($e->errors())->flatten()->first() ?? 'The sale could not be recorded.',
                'errors' => $e->errors(),
            ]));
        }

        return $this->record($shop, $pusher, $deviceId, $event, $hash, $record, $this->outcome($localId, 'processed', [
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'total' => $sale->total,
            'duplicate' => ! $sale->wasRecentlyCreated,
        ]), $sale->id);
    }

    private function resolveCashier(Shop $shop, User $pusher, bool $isManager, mixed $requested): ?User
    {
        if ($requested === null || (int) $requested === $pusher->id) {
            return $pusher;
        }

        if (! $isManager) {
            return null;
        }

        return User::whereHas('shopRoles', fn ($q) => $q->where('shop_id', $shop->id))->find((int) $requested);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function record(Shop $shop, User $pusher, string $deviceId, array $event, string $hash, ?SyncEvent $existing, array $outcome, ?int $entityId = null): array
    {
        $attributes = [
            'user_id' => $pusher->id,
            'entity_type' => $event['entity_type'],
            'operation' => $event['operation'],
            'payload_hash' => $hash,
            'status' => $outcome['status'],
            'entity_id' => $entityId,
            'result' => $outcome,
            'processed_at' => now(),
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            SyncEvent::create($attributes + [
                'shop_id' => $shop->id,
                'device_id' => $deviceId,
                'local_event_id' => $event['local_event_id'],
            ]);
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function outcome(string $localId, string $status, array $extra = []): array
    {
        return ['local_event_id' => $localId, 'status' => $status] + $extra;
    }
}

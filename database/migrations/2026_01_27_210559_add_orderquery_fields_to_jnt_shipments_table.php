<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'jnt_shipments';

    // Explicit index names (so we can check safely)
    private string $idxCreateOrderTime = 'jnt_shipments_create_order_time_idx';
    private string $idxOrderqueryQueriedAt = 'jnt_shipments_orderquery_queried_at_idx';

    private function columnExists(string $column): bool
    {
        return Schema::hasColumn($this->table, $column);
    }

    /**
     * MySQL-safe index existence check (no doctrine/dbal needed).
     */
    private function indexExists(string $indexName): bool
    {
        try {
            $row = DB::selectOne(
                "SELECT 1 AS `ok`
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?
                 LIMIT 1",
                [$this->table, $indexName]
            );

            return (bool) $row;
        } catch (\Throwable $e) {
            // If anything goes wrong, fail “safe” by assuming it exists (avoid migration crash)
            return true;
        }
    }

    public function up(): void
    {
        Schema::table($this->table, function (Blueprint $table) {

            // Anchor columns (only use ->after() if the anchor exists)
            $afterReceiverArea   = $this->columnExists('receiver_area') ? 'receiver_area' : null;
            $afterResponsePayload = $this->columnExists('response_payload') ? 'response_payload' : null;

            // 1) receiver_address (text)
            if (!$this->columnExists('receiver_address')) {
                if ($afterReceiverArea) $table->text('receiver_address')->nullable()->after($afterReceiverArea);
                else $table->text('receiver_address')->nullable();
            }

            // Decide next anchors in sequence (keep your intended ordering)
            $afterReceiverAddress = $this->columnExists('receiver_address') ? 'receiver_address' : ($afterReceiverArea ?? null);

            // 2) order_status
            if (!$this->columnExists('order_status')) {
                if ($afterReceiverAddress) $table->string('order_status', 50)->nullable()->after($afterReceiverAddress);
                else $table->string('order_status', 50)->nullable();
            }

            $afterOrderStatus = $this->columnExists('order_status') ? 'order_status' : ($afterReceiverAddress ?? null);

            // 3) create_order_time
            if (!$this->columnExists('create_order_time')) {
                if ($afterOrderStatus) $table->dateTime('create_order_time')->nullable()->after($afterOrderStatus);
                else $table->dateTime('create_order_time')->nullable();
            }

            $afterCreateOrderTime = $this->columnExists('create_order_time') ? 'create_order_time' : ($afterOrderStatus ?? null);

            // 4) send_start_time
            if (!$this->columnExists('send_start_time')) {
                if ($afterCreateOrderTime) $table->dateTime('send_start_time')->nullable()->after($afterCreateOrderTime);
                else $table->dateTime('send_start_time')->nullable();
            }

            $afterSendStartTime = $this->columnExists('send_start_time') ? 'send_start_time' : ($afterCreateOrderTime ?? null);

            // 5) send_end_time
            if (!$this->columnExists('send_end_time')) {
                if ($afterSendStartTime) $table->dateTime('send_end_time')->nullable()->after($afterSendStartTime);
                else $table->dateTime('send_end_time')->nullable();
            }

            $afterSendEndTime = $this->columnExists('send_end_time') ? 'send_end_time' : ($afterSendStartTime ?? null);

            // 6) weight
            if (!$this->columnExists('weight')) {
                if ($afterSendEndTime) $table->decimal('weight', 10, 3)->nullable()->after($afterSendEndTime);
                else $table->decimal('weight', 10, 3)->nullable();
            }

            $afterWeight = $this->columnExists('weight') ? 'weight' : ($afterSendEndTime ?? null);

            // 7) charge_weight
            if (!$this->columnExists('charge_weight')) {
                if ($afterWeight) $table->decimal('charge_weight', 10, 3)->nullable()->after($afterWeight);
                else $table->decimal('charge_weight', 10, 3)->nullable();
            }

            $afterChargeWeight = $this->columnExists('charge_weight') ? 'charge_weight' : ($afterWeight ?? null);

            // 8) sumfreight
            if (!$this->columnExists('sumfreight')) {
                if ($afterChargeWeight) $table->decimal('sumfreight', 10, 2)->nullable()->after($afterChargeWeight);
                else $table->decimal('sumfreight', 10, 2)->nullable();
            }

            $afterSumfreight = $this->columnExists('sumfreight') ? 'sumfreight' : ($afterChargeWeight ?? null);

            // 9) offer_fee
            if (!$this->columnExists('offer_fee')) {
                if ($afterSumfreight) $table->decimal('offer_fee', 10, 2)->nullable()->after($afterSumfreight);
                else $table->decimal('offer_fee', 10, 2)->nullable();
            }

            $afterOfferFee = $this->columnExists('offer_fee') ? 'offer_fee' : ($afterSumfreight ?? null);

            // 10) goods_value
            if (!$this->columnExists('goods_value')) {
                if ($afterOfferFee) $table->decimal('goods_value', 10, 2)->nullable()->after($afterOfferFee);
                else $table->decimal('goods_value', 10, 2)->nullable();
            }

            $afterGoodsValue = $this->columnExists('goods_value') ? 'goods_value' : ($afterOfferFee ?? null);

            // 11) goods_names
            if (!$this->columnExists('goods_names')) {
                if ($afterGoodsValue) $table->text('goods_names')->nullable()->after($afterGoodsValue);
                else $table->text('goods_names')->nullable();
            }

            // 12) orderquery_raw (json) + orderquery_queried_at
            if (!$this->columnExists('orderquery_raw')) {
                if ($afterResponsePayload) $table->json('orderquery_raw')->nullable()->after($afterResponsePayload);
                else $table->json('orderquery_raw')->nullable();
            }

            $afterOrderqueryRaw = $this->columnExists('orderquery_raw') ? 'orderquery_raw' : ($afterResponsePayload ?? null);

            if (!$this->columnExists('orderquery_queried_at')) {
                if ($afterOrderqueryRaw) $table->dateTime('orderquery_queried_at')->nullable()->after($afterOrderqueryRaw);
                else $table->dateTime('orderquery_queried_at')->nullable();
            }
        });

        // Add indexes safely (outside Schema::table closure is fine too)
        Schema::table($this->table, function (Blueprint $table) {
            if ($this->columnExists('create_order_time') && !$this->indexExists($this->idxCreateOrderTime)) {
                $table->index('create_order_time', $this->idxCreateOrderTime);
            }

            if ($this->columnExists('orderquery_queried_at') && !$this->indexExists($this->idxOrderqueryQueriedAt)) {
                $table->index('orderquery_queried_at', $this->idxOrderqueryQueriedAt);
            }
        });
    }

    public function down(): void
    {
        // Drop indexes first (if they exist), then drop columns (if they exist)
        Schema::table($this->table, function (Blueprint $table) {
            if ($this->indexExists($this->idxCreateOrderTime)) {
                $table->dropIndex($this->idxCreateOrderTime);
            }
            if ($this->indexExists($this->idxOrderqueryQueriedAt)) {
                $table->dropIndex($this->idxOrderqueryQueriedAt);
            }

            // Drop columns only if present (MySQL will error if not)
            $drops = [];

            foreach ([
                'receiver_address',
                'order_status',
                'create_order_time',
                'send_start_time',
                'send_end_time',
                'weight',
                'charge_weight',
                'sumfreight',
                'offer_fee',
                'goods_value',
                'goods_names',
                'orderquery_raw',
                'orderquery_queried_at',
            ] as $col) {
                if ($this->columnExists($col)) $drops[] = $col;
            }

            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};

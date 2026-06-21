<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class OnlineOrder extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'online_orders';

    public function withDetails(int $id): ?array
    {
        $order = $this->find($id);
        if (!$order) {
            return null;
        }
        $order['items'] = $this->rawAll('SELECT * FROM online_order_items WHERE order_id = :id', ['id' => $id]);
        $order['events'] = $this->rawAll('SELECT * FROM online_order_events WHERE order_id = :id ORDER BY id ASC', ['id' => $id]);
        return $order;
    }
}

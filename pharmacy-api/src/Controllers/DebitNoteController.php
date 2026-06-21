<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\DebitNote;

final class DebitNoteController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['note_no', 'party_name', 'against_invoice', 'amount', 'gst_amount', 'reason', 'note_date'];
    protected array $searchColumns = ['note_no', 'party_name', 'against_invoice'];
    protected string $defaultOrder = 'note_date DESC, id DESC';

    protected function model(): Model
    {
        return new DebitNote();
    }

    protected function storeRules(): array
    {
        return [
            'party_name'      => 'required|string|max:150',
            'against_invoice' => 'string|max:60',
            'amount'          => 'required|numeric|min:0',
            'gst_amount'      => 'numeric|min:0',
            'reason'          => 'string|max:255',
            'note_date'       => 'required|string|max:20',
        ];
    }
}

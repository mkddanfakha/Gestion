<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SaleCancel = 'sale_cancel';
    case DeliveryNote = 'delivery_note';
    case DeliveryNoteCancel = 'delivery_note_cancel';
    case InventoryAdjustment = 'inventory_adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
    case ManualAdjustment = 'manual_adjustment';

    public function isInbound(): bool
    {
        return $this->quantitySign() > 0;
    }

    public function isOutbound(): bool
    {
        return $this->quantitySign() < 0;
    }

    /**
     * Signe attendu du delta quantity pour ce type de mouvement.
     * Les ajustements manuels et inventaire acceptent les deux sens via adjust().
     */
    public function quantitySign(): int
    {
        return match ($this) {
            self::OpeningBalance,
            self::Purchase,
            self::SaleCancel,
            self::DeliveryNote,
            self::TransferIn,
            self::ReturnIn => 1,
            self::Sale,
            self::DeliveryNoteCancel,
            self::TransferOut,
            self::ReturnOut => -1,
            self::InventoryAdjustment,
            self::ManualAdjustment => 0,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

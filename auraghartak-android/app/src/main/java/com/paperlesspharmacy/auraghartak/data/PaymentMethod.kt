package com.paperlesspharmacy.auraghartak.data

data class PaymentOption(
    val id: String,
    val label: String,
    val hint: String,
)

object PaymentMethods {
    val options: List<PaymentOption> = listOf(
        PaymentOption(
            id = "cod",
            label = "Cash on delivery",
            hint = "Pay in cash when your order arrives",
        ),
        PaymentOption(
            id = "upi_delivery",
            label = "UPI on delivery",
            hint = "Pay via UPI / QR when the delivery reaches you",
        ),
        PaymentOption(
            id = "card_delivery",
            label = "Card on delivery",
            hint = "Debit or credit card with the delivery person",
        ),
        PaymentOption(
            id = "pay_at_pharmacy",
            label = "Pay at pharmacy",
            hint = "Pick up and pay at the store counter",
        ),
    )

    fun labelFor(id: String): String =
        options.firstOrNull { it.id == id }?.label ?: options.first().label

    fun appendToNote(baseNote: String, paymentId: String): String {
        val pay = "Payment: ${labelFor(paymentId)}"
        val trimmed = baseNote.trim()
        return if (trimmed.isBlank()) pay else "$pay · $trimmed"
    }
}

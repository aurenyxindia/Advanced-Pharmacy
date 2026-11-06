package in.paperlesspharmacy.auraghatak.shop

data class CartLine(
    val productName: String,
    val batchNo: String,
    val unitMrp: Double,
    val maxQty: Int,
    val qty: Int,
) {
    val lineTotal: Double get() = unitMrp * qty
}

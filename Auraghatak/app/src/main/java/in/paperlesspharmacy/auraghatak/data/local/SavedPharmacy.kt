package in.paperlesspharmacy.auraghatak.data.local

data class SavedPharmacy(
    val token: String,
    val name: String,
    val address: String,
    val contact: String,
    val patientName: String,
    val savedAt: Long = System.currentTimeMillis(),
)

data class LocalOrder(
    val orderNo: String,
    val pharmacyName: String,
    val estimatedTotal: Double,
    val placedAt: Long = System.currentTimeMillis(),
)

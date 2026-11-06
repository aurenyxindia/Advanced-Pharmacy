package com.paperlesspharmacy.auraghartak.data

data class Branch(
    val id: Long,
    val name: String,
    val city: String,
    val businessName: String,
    val source: String = "advanced",
    val clientNo: String = "",
    val state: String = "",
    val pincode: String = "",
    val address: String = "",
    val latitude: Double? = null,
    val longitude: Double? = null,
    val phone: String = "",
) {
    fun locationKey(): String = if (source == "retail") clientNo.trim() else id.toString()

    fun hasStrongLocation(): Boolean =
        pinForDistance().length == 6 ||
            (city.isNotBlank() && state.isNotBlank()) ||
            address.trim().length >= 12

    /** Weak = only state / shop name — do not geocode (wrong distance). */
    fun isWeakRetailLocation(): Boolean =
        source == "retail" && !hasStrongLocation()

    fun geocodeQuery(nearUser: ResolvedPlace? = null): String {
        if (hasStrongLocation()) {
            val pin = pinForDistance()
            if (pin.length == 6 && address.isBlank() && city.isBlank()) {
                return "$pin, India"
            }
            val parts = listOf(address, city, pincode, state, "India").map { it.trim() }.filter { it.isNotEmpty() }
            return parts.distinct().joinToString(", ")
        }
        // Never geocode "shop name + your city/PIN" — that pins distant retail shops next to you.
        return ""
    }

    fun distanceKmFrom(userLat: Double, userLng: Double, pinLatLng: Pair<Double, Double>? = null): Double? {
        val lat = latitude
        val lng = longitude
        if (Geo.isValidCoord(lat, lng)) {
            return Geo.distanceKm(userLat, userLng, lat!!, lng!!)
        }
        pinLatLng?.let { return Geo.distanceKm(userLat, userLng, it.first, it.second) }
        return null
    }

    fun pinForDistance(): String = NearYou.pinDigits(pincode)

    fun hasCoordinates(): Boolean = Geo.isValidCoord(latitude, longitude)
    val title: String get() = businessName.ifBlank { name.ifBlank { "Pharmacy" } }

    val subtitle: String
        get() {
            val branch = name.takeIf { it.isNotBlank() && !it.equals(title, ignoreCase = true) }
            val place = listOfNotNull(
                city.ifBlank { null },
                state.takeIf { it.isNotBlank() && !it.equals(city, ignoreCase = true) },
                pincode.takeIf { it.isNotBlank() }?.let { "PIN $it" },
            ).joinToString(" · ")
            return listOfNotNull(branch, place.ifBlank { null }).joinToString(" · ")
        }
}

data class CatalogItem(
    val bindingId: Long,
    val productName: String,
    val scheduleCategory: String,
    val availableQty: Double,
    /** Per tablet / loose unit — the quantity the customer orders. */
    val mrp: Double,
    val detail: String = "",
    val unitsPerPack: Int = 1,
    val packSize: String = "",
)

data class CartLine(
    val bindingId: Long,
    val name: String,
    val qty: Int,
    val rate: Double,
    val maxQty: Int,
    val schedule: String,
)

data class Profile(
    val name: String = "",
    val phone: String = "",
    val address: String = "",
    val pincode: String = "",
)

/** Logged-in AuraGharTak app customer (server `auraghatak_users`). */
data class AgtUserAccount(
    val id: Long = 0,
    val fullName: String = "",
    val email: String = "",
    val phone: String = "",
    val address: String = "",
    val pincode: String = "",
    val city: String = "",
    val state: String = "",
    val dateOfBirth: String = "",
    val gender: String = "",
    val photoUrl: String = "",
) {
    fun toProfile(): Profile = Profile(
        name = fullName,
        phone = phone,
        address = address,
        pincode = pincode,
    )
}

data class SavedOrder(
    val orderNo: String,
    val trackingToken: String = "",
    val pharmacyName: String = "",
    val branchId: Long = 0,
    val total: Double = 0.0,
    val createdAt: String = "",
    val status: String = "placed",
    val kind: String = "store",
    val phone: String = "",
    val lines: List<SavedOrderLine>? = emptyList(),
) {
    fun orderLines(): List<SavedOrderLine> = lines.orEmpty()
}

data class SubstituteItem(
    val bindingId: Long,
    val productName: String,
    val scheduleCategory: String,
    val availableQty: Double,
    val mrp: Double,
    val genericName: String = "",
    val unitsPerPack: Int = 1,
    val packSize: String = "",
)

data class RetailOrderSummary(
    val orderNo: String,
    val pharmacyName: String,
    val status: String,
    val total: Double,
    val createdAt: String,
    val clientNo: String = "",
)

data class OrderSummary(
    val orderNo: String,
    val customerName: String,
    val status: String,
    val total: Double,
    val createdAt: String,
    val orderKind: String = "store",
)

data class OrderEvent(
    val status: String,
    val note: String,
    val createdAt: String,
)

data class OrderDetail(
    val orderNo: String,
    val customerName: String,
    val phone: String,
    val status: String,
    val total: Double,
    val createdAt: String,
    val cancellable: Boolean,
    val events: List<OrderEvent>,
    val pharmacyName: String = "",
    val address: String = "",
    val lines: List<SavedOrderLine> = emptyList(),
)

data class AgtProduct(
    val name: String,
    val batchNo: String,
    val packSize: String,
    val category: String,
    val unitMrp: Double,
    val availableQty: Double,
    val expiry: String,
)

data class AgtShop(
    val token: String,
    val pharmacyName: String,
    val pharmacyContact: String,
    val pharmacyAddress: String,
    val patientName: String,
    val patientContact: String,
    val patientAddress: String,
    val products: List<AgtProduct>,
)

data class AgtCartLine(
    val name: String,
    val batchNo: String,
    val qty: Int,
    val unitMrp: Double,
    val maxQty: Int,
)

data class PlaceResult(
    val orderNo: String,
    val trackingToken: String,
    val total: Double,
)

data class AgtPlaceResult(
    val orderNo: String,
    val total: Double,
)

sealed class TrackResult {
    data class One(val detail: OrderDetail) : TrackResult()
    data class Many(val orders: List<OrderSummary>) : TrackResult()
}

open class ApiException(message: String) : Exception(message)

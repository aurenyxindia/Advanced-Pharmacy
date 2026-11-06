package com.paperlesspharmacy.auraghartak.data

/**
 * Retail `listClients` often omits Pin/address. Bootstrap known shops until serverdev sends full rows.
 * Keys = retail `client_no`.
 */
object RetailLocationOverrides {
    data class Hint(
        val pin: String = "",
        val city: String = "",
        val address: String = "",
        val latitude: Double? = null,
        val longitude: Double? = null,
    )

    private val byClient: Map<String, Hint> = mapOf(
        // Cyclone Pharmaceuticals — Viman Nagar, Pune (nearby for AGT demo / local users)
        "001" to Hint(
            pin = "411014",
            city = "Pune",
            address = "Cyclone Pharmaceuticals Pvt. Ltd., Viman Nagar, Pune, Maharashtra",
        ),
    )

    fun enrich(branch: Branch): Branch {
        if (branch.source != "retail") return branch
        val code = branch.clientNo.trim()
        val hint = byClient[code] ?: return branch
        return branch.copy(
            pincode = branch.pincode.ifBlank { hint.pin },
            city = branch.city.ifBlank { hint.city },
            address = branch.address.ifBlank { hint.address },
            latitude = hint.latitude ?: branch.latitude,
            longitude = hint.longitude ?: branch.longitude,
        )
    }

    fun enrichAll(branches: List<Branch>): List<Branch> = branches.map { enrich(it) }
}

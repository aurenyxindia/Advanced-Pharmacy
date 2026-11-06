package `in`.paperlesspharmacy.advanced.data.master

import `in`.paperlesspharmacy.advanced.data.MASTERS_OPS_ROLES

object MasterVisibility {
    private val homeOrder = listOf(
        "products", "generics", "manufacturers", "hsn", "distributors",
        "customers", "doctors", "hospitals", "accounts", "rate-margins", "employees",
    )

    private val universal = setOf("products", "generics", "manufacturers", "hsn", "distributors")
    private val opsOnly = setOf("customers", "doctors", "hospitals")
    private val adminOnly = setOf("accounts", "rate-margins", "employees")

    fun homeTiles(role: String?, configs: Map<String, MasterConfigDto>): List<MasterConfigDto> {
        if (role.isNullOrBlank()) return emptyList()
        val ops = MASTERS_OPS_ROLES.contains(role)
        val admin = role == "ho_admin" || role == "branch_admin"
        return homeOrder.mapNotNull { key ->
            val def = configs[key] ?: return@mapNotNull null
            when {
                key in adminOnly && !admin -> null
                key in universal -> def
                key in opsOnly && ops -> def
                key in opsOnly -> null
                else -> def
            }
        }
    }
}

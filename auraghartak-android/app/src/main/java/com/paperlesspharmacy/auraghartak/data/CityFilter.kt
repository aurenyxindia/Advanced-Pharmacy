package com.paperlesspharmacy.auraghartak.data

object CityFilter {
    fun branchMatches(selectedCity: String, branch: Branch): Boolean {
        val needle = selectedCity.trim()
        if (needle.isBlank()) return true
        if (branch.city.equals(needle, ignoreCase = true)) return true
        if (branch.state.equals(needle, ignoreCase = true)) return true
        if (branch.address.contains(needle, ignoreCase = true)) return true
        val pin = branch.pinForDistance()
        if (pin.length == 6) {
            pinHintCity(pin)?.equals(needle, ignoreCase = true)?.let { if (it) return true }
        }
        return false
    }

    /** Common chip labels for Indian PIN prefixes (best-effort). */
    fun pinHintCity(pin: String): String? = when (pin.take(3)) {
        "411" -> "Pune"
        "400", "401" -> "Mumbai"
        "110" -> "Delhi"
        "560" -> "Bengaluru"
        "500" -> "Hyderabad"
        "600" -> "Chennai"
        "700" -> "Kolkata"
        else -> null
    }

    fun chipsFromBranches(branches: List<Branch>, userCity: String): List<String> {
        val fromData = branches.flatMap { b ->
            buildList {
                b.city.trim().takeIf { it.isNotEmpty() }?.let { add(it) }
                b.state.trim().takeIf { it.isNotEmpty() }?.let { add(it) }
                pinHintCity(b.pinForDistance())?.let { add(it) }
            }
        }
        val mine = userCity.trim()
        return (fromData + listOfNotNull(mine.takeIf { it.isNotEmpty() }))
            .distinctBy { it.lowercase() }
            .sortedBy { it.lowercase() }
    }
}

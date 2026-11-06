package com.paperlesspharmacy.auraghartak.data

/** Lower score = closer match (pincode + city + state). */
object NearYou {
    const val SCORE_EXACT_PIN = 0
    const val SCORE_PIN_AREA = 1
    const val SCORE_SAME_CITY = 2
    const val SCORE_SAME_STATE = 3
    const val SCORE_FAR = 99

    fun pinDigits(raw: String): String = raw.filter { it.isDigit() }.take(6)

    fun score(userCity: String, userPincode: String, userState: String, branch: Branch): Int {
        val up = pinDigits(userPincode)
        val bp = pinDigits(branch.pincode)
        val uc = userCity.trim()
        val us = userState.trim()
        val bc = branch.city.trim()
        val bs = branch.state.trim()

        if (up.length == 6 && bp.length == 6 && up == bp) return SCORE_EXACT_PIN
        if (up.length >= 3 && bp.length >= 3 && up.take(3) == bp.take(3)) return SCORE_PIN_AREA
        if (uc.isNotBlank() && bc.isNotBlank() && uc.equals(bc, ignoreCase = true)) return SCORE_SAME_CITY
        if (us.isNotBlank()) {
            if (bs.isNotBlank() && us.equals(bs, ignoreCase = true)) return SCORE_SAME_STATE
            if (bc.isNotBlank() && us.equals(bc, ignoreCase = true)) return SCORE_SAME_STATE
        }
        return SCORE_FAR
    }

    fun label(score: Int): String? = when (score) {
        SCORE_EXACT_PIN -> "Your PIN"
        SCORE_PIN_AREA -> "Nearby PIN"
        SCORE_SAME_CITY -> "Your city"
        SCORE_SAME_STATE -> "Your state"
        else -> null
    }

    fun hasUserLocation(userCity: String, userPincode: String, userState: String): Boolean =
        userCity.isNotBlank() || pinDigits(userPincode).length >= 3 || userState.isNotBlank()

    /** Tightest score still counted as "near you" (lower = stricter nearby list). */
    fun maxNearbyScore(userCity: String, userPincode: String, userState: String): Int {
        val up = pinDigits(userPincode)
        if (up.length >= 6) return SCORE_EXACT_PIN
        if (up.length >= 3) return SCORE_PIN_AREA
        if (userCity.isNotBlank()) return SCORE_SAME_CITY
        if (userState.isNotBlank()) return SCORE_SAME_STATE
        return SCORE_FAR
    }

    /** Retail rows with only state — never treat as nearby on state match alone. */
    fun maxNearbyScoreForBranch(
        userCity: String,
        userPincode: String,
        userState: String,
        branch: Branch,
    ): Int {
        if (branch.isWeakRetailLocation()) {
            val up = pinDigits(userPincode)
            if (up.length >= 6) return SCORE_PIN_AREA
            if (up.length >= 3) return SCORE_PIN_AREA
            if (userCity.isNotBlank()) return SCORE_SAME_CITY
            return SCORE_FAR
        }
        val up = pinDigits(userPincode)
        if (up.length >= 6) return SCORE_PIN_AREA
        return maxNearbyScore(userCity, userPincode, userState)
    }

    /** Profile / PIN mode: hide whole-state matches when user gave a 6-digit PIN. */
    fun passesPinNearbyFilter(userPincode: String, branch: Branch): Boolean {
        val up = pinDigits(userPincode)
        val bp = pinDigits(branch.pincode)
        if (up.length == 6 && bp.length == 6) {
            return up.take(3) == bp.take(3) || up == bp
        }
        if (up.length == 6 && bp.isEmpty() && branch.hasStrongLocation()) {
            return score("", up, "", branch) <= SCORE_SAME_CITY
        }
        if (up.length == 6) {
            return branch.isWeakRetailLocation().not() && score("", up, "", branch) <= SCORE_PIN_AREA
        }
        return true
    }
}

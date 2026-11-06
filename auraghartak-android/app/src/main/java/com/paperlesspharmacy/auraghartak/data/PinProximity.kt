package com.paperlesspharmacy.auraghartak.data

import kotlin.math.abs

/**
 * Instant sort key from 6-digit PINs (no network). Used until geocoder fills in km.
 * Lower = closer. Not shown as exact km in UI unless geocoded.
 */
object PinProximity {
    fun estimateKm(userPin: String, branchPin: String): Double? {
        val u = NearYou.pinDigits(userPin)
        val b = NearYou.pinDigits(branchPin)
        if (u.length != 6 || b.length != 6) return null
        if (u == b) return 0.5
        if (u.take(3) == b.take(3)) {
            val uTail = u.substring(3).toIntOrNull() ?: 0
            val bTail = b.substring(3).toIntOrNull() ?: 0
            return 3.0 + abs(uTail - bTail) * 0.05
        }
        // Different sorting district (e.g. Pune 411xxx vs Solapur 413xxx) — not nearby.
        return 400.0
    }

    fun samePinArea(userPin: String, branchPin: String): Boolean {
        val u = NearYou.pinDigits(userPin)
        val b = NearYou.pinDigits(branchPin)
        return u.length == 6 && b.length == 6 && u.take(3) == b.take(3)
    }
}

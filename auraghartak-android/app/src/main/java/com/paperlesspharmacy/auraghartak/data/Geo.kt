package com.paperlesspharmacy.auraghartak.data

import kotlin.math.asin
import kotlin.math.cos
import kotlin.math.pow
import kotlin.math.sin
import kotlin.math.sqrt

object Geo {
    val radiusOptionsKm: List<Int> = listOf(1, 5, 10, 25, 50)
    const val DEFAULT_RADIUS_KM = 10.0
    private const val EARTH_KM = 6371.0

    fun distanceKm(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
        val dLat = Math.toRadians(lat2 - lat1)
        val dLng = Math.toRadians(lng2 - lng1)
        val a = sin(dLat / 2).pow(2.0) +
            cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) * sin(dLng / 2).pow(2.0)
        val c = 2 * asin(sqrt(a.coerceIn(0.0, 1.0)))
        return EARTH_KM * c
    }

    fun formatDistanceKm(km: Double): String = when {
        km < 0.1 -> "< 100 m"
        km < 1.0 -> "${(km * 1000).toInt()} m"
        km < 10.0 -> String.format("%.1f km", km)
        else -> String.format("%.0f km", km)
    }

    fun isValidCoord(lat: Double?, lng: Double?): Boolean =
        lat != null && lng != null && lat in -90.0..90.0 && lng in -180.0..180.0
}

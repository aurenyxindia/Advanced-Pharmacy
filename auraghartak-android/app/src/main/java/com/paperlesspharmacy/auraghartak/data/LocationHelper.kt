package com.paperlesspharmacy.auraghartak.data

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Geocoder
import android.location.Location
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.tasks.await
import java.util.Locale

data class ResolvedPlace(
    val city: String = "",
    val state: String = "",
    val pincode: String = "",
) {
    fun hasUsableArea(): Boolean =
        city.isNotBlank() || NearYou.pinDigits(pincode).length >= 3 || state.isNotBlank()
}

class LocationHelper(private val context: Context) {

    fun hasFineLocation(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    fun hasCoarseLocation(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    fun canLocate(): Boolean = hasFineLocation() || hasCoarseLocation()

    suspend fun currentLatLng(): Pair<Double, Double>? {
        if (!canLocate()) return null
        val client = LocationServices.getFusedLocationProviderClient(context)
        val token = CancellationTokenSource()
        val loc: Location? = runCatching {
            client.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, token.token).await()
        }.getOrNull() ?: runCatching { client.lastLocation.await() }.getOrNull()
        if (loc == null || !loc.latitude.isFinite() || !loc.longitude.isFinite()) return null
        return loc.latitude to loc.longitude
    }

    /** Turn phone GPS into city / state / PIN to match pharmacy addresses (no shop lat/lng needed). */
    fun resolvePlace(lat: Double, lng: Double): ResolvedPlace? {
        if (!Geocoder.isPresent()) return null
        val geocoder = Geocoder(context, Locale.getDefault())
        @Suppress("DEPRECATION")
        val list = runCatching { geocoder.getFromLocation(lat, lng, 1) }.getOrNull() ?: return null
        val address = list.firstOrNull() ?: return null
        val city = sequenceOf(
            address.locality,
            address.subAdminArea,
            address.subLocality,
        ).firstOrNull { !it.isNullOrBlank() }.orEmpty().trim()
        val state = address.adminArea?.trim().orEmpty()
        val pincode = address.postalCode?.filter { it.isDigit() }?.take(6).orEmpty()
        val place = ResolvedPlace(city = city, state = state, pincode = pincode)
        return place.takeIf { it.hasUsableArea() }
    }

    /** One-time style lookup from pharmacy address text (cached by caller). */
    /** Approximate lat/lng for an Indian 6-digit PIN (sorting / radius). */
    fun geocodePincode(pin: String): Pair<Double, Double>? {
        val digits = NearYou.pinDigits(pin)
        if (digits.length != 6) return null
        return geocodeAddress("$digits, India")
    }

    fun geocodeAddress(query: String): Pair<Double, Double>? {
        val q = query.trim()
        if (q.isBlank() || !Geocoder.isPresent()) return null
        val geocoder = Geocoder(context, Locale.getDefault())
        @Suppress("DEPRECATION")
        val list = runCatching { geocoder.getFromLocationName(q, 1) }.getOrNull() ?: return null
        val address = list.firstOrNull() ?: return null
        val lat = address.latitude
        val lng = address.longitude
        if (!lat.isFinite() || !lng.isFinite()) return null
        return lat to lng
    }
}

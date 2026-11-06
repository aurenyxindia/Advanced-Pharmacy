package com.paperlesspharmacy.auraghartak.notify

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.paperlesspharmacy.auraghartak.MainActivity
import com.paperlesspharmacy.auraghartak.R
import com.paperlesspharmacy.auraghartak.data.DeliveryApi
import com.paperlesspharmacy.auraghartak.data.SessionExpiredException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Foreground service that sends the rider's GPS to the server while a delivery is out.
 * Started from the open app (while-in-use location), so it keeps running with the screen off.
 */
class RiderLocationService : Service() {

    companion object {
        private const val CHANNEL = "agt_rider_live"
        private const val NOTIFICATION_ID = 7301
        private const val INTERVAL_MS = 8_000L

        fun start(context: Context) {
            ContextCompat.startForegroundService(context, Intent(context, RiderLocationService::class.java))
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, RiderLocationService::class.java))
        }

        fun hasLocationPermission(context: Context): Boolean =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val sending = AtomicBoolean(false)
    private lateinit var fused: FusedLocationProviderClient
    private lateinit var api: DeliveryApi

    private val callback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val loc = result.lastLocation ?: return
            if (!sending.compareAndSet(false, true)) return
            scope.launch {
                try {
                    val keep = api.ping(
                        loc.latitude,
                        loc.longitude,
                        loc.takeIf { it.hasAccuracy() }?.accuracy,
                        loc.takeIf { it.hasSpeed() }?.speed,
                        loc.takeIf { it.hasBearing() }?.bearing,
                    )
                    if (!keep) stopSelf()
                } catch (_: SessionExpiredException) {
                    stopSelf()
                } catch (_: Exception) {
                    // Network blip — next fix retries.
                } finally {
                    sending.set(false)
                }
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        fused = LocationServices.getFusedLocationProviderClient(this)
        api = DeliveryApi(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (!hasLocationPermission(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        ensureChannel()
        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        ServiceCompat.startForeground(this, NOTIFICATION_ID, buildNotification(), type)
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, INTERVAL_MS)
            .setMinUpdateIntervalMillis(INTERVAL_MS / 2)
            .setMinUpdateDistanceMeters(5f)
            .build()
        try {
            fused.removeLocationUpdates(callback)
            fused.requestLocationUpdates(request, callback, Looper.getMainLooper())
        } catch (_: SecurityException) {
            stopSelf()
        }
        return START_STICKY
    }

    override fun onDestroy() {
        runCatching { fused.removeLocationUpdates(callback) }
        scope.cancel()
        super.onDestroy()
    }

    private fun buildNotification() = NotificationCompat.Builder(this, CHANNEL)
        .setSmallIcon(R.drawable.ic_launcher_foreground)
        .setContentTitle("Delivering order")
        .setContentText("Sharing your live location with the customer")
        .setOngoing(true)
        .setOnlyAlertOnce(true)
        .setCategory(NotificationCompat.CATEGORY_SERVICE)
        .setContentIntent(
            PendingIntent.getActivity(
                this,
                NOTIFICATION_ID,
                Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            ),
        )
        .build()

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val mgr = getSystemService(NotificationManager::class.java) ?: return
        mgr.createNotificationChannel(
            NotificationChannel(CHANNEL, "Live delivery location", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Shown while you are delivering an order"
                setShowBadge(false)
            },
        )
    }
}

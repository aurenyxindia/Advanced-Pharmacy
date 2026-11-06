package com.paperlesspharmacy.auraghartak.ui

import android.content.Context
import android.content.Intent
import android.graphics.Color as AndroidColor
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.DeliveryDining
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.paperlesspharmacy.auraghartak.data.ApiException
import com.paperlesspharmacy.auraghartak.data.DeliveryApi
import com.paperlesspharmacy.auraghartak.data.GeoPoint
import com.paperlesspharmacy.auraghartak.data.LiveDelivery
import com.paperlesspharmacy.auraghartak.notify.OrderStatusSync
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import org.osmdroid.config.Configuration
import org.osmdroid.tileprovider.tilesource.TileSourceFactory
import org.osmdroid.util.BoundingBox
import org.osmdroid.views.MapView
import org.osmdroid.views.overlay.Marker
import org.osmdroid.views.overlay.Polyline
import kotlin.math.atan2
import kotlin.math.ceil
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt
import org.osmdroid.util.GeoPoint as OsmPoint

private const val LIVE_POLL_MS = 5_000L
private const val IDLE_POLL_MS = 20_000L

/** Live rider map on the customer's order screen — shown once the order has a rider or is out for delivery. */
@Composable
fun LiveDeliveryCard(orderNo: String, fallbackStatus: String) {
    val context = LocalContext.current
    val api = remember { DeliveryApi(context) }
    var live by remember(orderNo) { mutableStateOf<LiveDelivery?>(null) }
    var fullView by remember(orderNo) { mutableStateOf(false) }

    LaunchedEffect(orderNo) {
        if (orderNo.isBlank()) return@LaunchedEffect
        while (true) {
            val next = try {
                withContext(Dispatchers.IO) { api.live(orderNo) }
            } catch (_: ApiException) {
                null
            }
            if (next != null) live = next
            val status = OrderStatusSync.normalizeStatus(next?.status ?: fallbackStatus)
            if (status == "delivered" || status == "cancelled") break
            delay(if (next?.trackingActive == true) LIVE_POLL_MS else IDLE_POLL_MS)
        }
    }

    val data = live ?: return
    val status = OrderStatusSync.normalizeStatus(data.status)
    val showMap = data.trackingActive || status == "out_for_delivery"
    if (!showMap && data.riderName.isBlank()) return

    Spacer(Modifier.height(AuraDimens.spaceMd))
    SoftCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.DeliveryDining, contentDescription = null, tint = Teal, modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(AuraDimens.spaceSm))
            Text(
                if (showMap) "Live tracking" else "Delivery partner assigned",
                style = MaterialTheme.typography.titleSmall,
                modifier = Modifier.weight(1f),
            )
            if (data.trackingActive && data.rider != null && data.riderAgeSeconds < 60) {
                Text("● LIVE", color = Success, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
            }
        }
        if (data.riderName.isNotBlank()) {
            Spacer(Modifier.height(4.dp))
            Text(data.riderName, style = MaterialTheme.typography.bodyMedium, color = Ink)
        }
        if (!showMap) {
            Text(
                "Live location appears here when they leave the pharmacy.",
                style = MaterialTheme.typography.bodySmall,
                color = Mute,
            )
            return@SoftCard
        }

        etaLabel(data)?.let {
            Spacer(Modifier.height(4.dp))
            Text(it, style = MaterialTheme.typography.titleMedium, color = TealDeep, fontWeight = FontWeight.Bold)
        }
        Text(freshnessLabel(data), style = MaterialTheme.typography.bodySmall, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        // Hide compact map while full view is open so only one MapView is active.
        if (!fullView) {
            LiveMap(
                data = data,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(260.dp)
                    .clip(RoundedCornerShape(AuraDimens.radiusLg)),
            )
        } else {
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(260.dp)
                    .clip(RoundedCornerShape(AuraDimens.radiusLg))
                    .background(SurfaceMuted),
                contentAlignment = Alignment.Center,
            ) {
                Text("Map open in full view", style = MaterialTheme.typography.bodyMedium, color = Mute)
            }
        }
        Text(
            "Map data © OpenStreetMap contributors",
            style = MaterialTheme.typography.labelSmall,
            color = Mute,
        )
        Spacer(Modifier.height(AuraDimens.spaceSm))
        SecondaryButton("Full view") { fullView = true }
        if (data.riderPhone.isNotBlank()) {
            Spacer(Modifier.height(AuraDimens.spaceSm))
            SecondaryButton("Call delivery partner") { dial(context, data.riderPhone) }
        }
    }

    if (fullView && showMap) {
        LiveTrackingFullView(
            data = data,
            onClose = { fullView = false },
            onCall = { if (data.riderPhone.isNotBlank()) dial(context, data.riderPhone) },
        )
    }
}

@Composable
private fun LiveTrackingFullView(
    data: LiveDelivery,
    onClose: () -> Unit,
    onCall: () -> Unit,
) {
    BackHandler(onBack = onClose)
    Dialog(
        onDismissRequest = onClose,
        properties = DialogProperties(
            usePlatformDefaultWidth = false,
            decorFitsSystemWindows = false,
        ),
    ) {
        Surface(modifier = Modifier.fillMaxSize(), color = Page) {
            Column(
                Modifier
                    .fillMaxSize()
                    .statusBarsPadding()
                    .navigationBarsPadding(),
            ) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .background(TealDeep)
                        .padding(horizontal = AuraDimens.spaceSm, vertical = AuraDimens.spaceSm),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f).padding(start = AuraDimens.spaceSm)) {
                        Text(
                            "Live tracking",
                            color = Color.White,
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.SemiBold,
                        )
                        if (data.riderName.isNotBlank()) {
                            Text(data.riderName, color = Color.White.copy(alpha = 0.9f), style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    if (data.trackingActive && data.rider != null && data.riderAgeSeconds < 60) {
                        Text(
                            "● LIVE",
                            color = Color(0xFF6EE7B7),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(end = AuraDimens.spaceSm),
                        )
                    }
                    IconButton(onClick = onClose) {
                        Icon(Icons.Outlined.Close, contentDescription = "Close", tint = Color.White)
                    }
                }

                Box(Modifier.weight(1f).fillMaxWidth()) {
                    LiveMap(data = data, modifier = Modifier.fillMaxSize())
                    Column(
                        Modifier
                            .align(Alignment.TopCenter)
                            .fillMaxWidth()
                            .padding(AuraDimens.spaceMd),
                    ) {
                        Surface(
                            shape = RoundedCornerShape(AuraDimens.radiusMd),
                            color = Color.White.copy(alpha = 0.95f),
                            shadowElevation = 4.dp,
                        ) {
                            Column(Modifier.padding(AuraDimens.spaceMd)) {
                                etaLabel(data)?.let {
                                    Text(it, style = MaterialTheme.typography.titleMedium, color = TealDeep, fontWeight = FontWeight.Bold)
                                }
                                Text(freshnessLabel(data), style = MaterialTheme.typography.bodySmall, color = Mute)
                                Text(
                                    "Pharmacy · Drop · Delivery partner",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = Mute,
                                    modifier = Modifier.padding(top = 4.dp),
                                )
                            }
                        }
                    }
                }

                Column(
                    Modifier
                        .fillMaxWidth()
                        .background(Color.White)
                        .padding(AuraDimens.spaceMd),
                ) {
                    Text(
                        "Map data © OpenStreetMap contributors",
                        style = MaterialTheme.typography.labelSmall,
                        color = Mute,
                    )
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    if (data.riderPhone.isNotBlank()) {
                        PrimaryButton("Call delivery partner", onClick = onCall)
                        Spacer(Modifier.height(AuraDimens.spaceSm))
                    }
                    SecondaryButton("Close full view", onClick = onClose)
                }
            }
        }
    }
}

@Composable
private fun LiveMap(data: LiveDelivery, modifier: Modifier) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    var fitted by remember { mutableStateOf(false) }
    val mapView = remember {
        Configuration.getInstance().apply {
            load(context, context.getSharedPreferences("osmdroid", Context.MODE_PRIVATE))
            userAgentValue = context.packageName
            osmdroidBasePath = java.io.File(context.cacheDir, "osmdroid")
            osmdroidTileCache = java.io.File(osmdroidBasePath, "tiles")
        }
        MapView(context).apply {
            setTileSource(TileSourceFactory.MAPNIK)
            setMultiTouchControls(true)
            zoomController.setVisibility(org.osmdroid.views.CustomZoomButtonsController.Visibility.NEVER)
            controller.setZoom(15.0)
        }
    }

    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        mapView.onResume()
        onDispose {
            lifecycle.removeObserver(observer)
            mapView.onPause()
            mapView.onDetach()
        }
    }

    AndroidView(factory = { mapView }, modifier = modifier) { map ->
        map.overlays.clear()
        if (data.trail.size >= 2) {
            map.overlays.add(
                Polyline(map).apply {
                    setPoints(data.trail.map { it.osm() })
                    outlinePaint.color = AndroidColor.parseColor("#14B8A6")
                    outlinePaint.strokeWidth = 8f
                },
            )
        }
        data.store?.let { map.overlays.add(dotMarker(map, it, "#334155", "Pharmacy")) }
        data.drop?.let { map.overlays.add(dotMarker(map, it, "#DC2626", "Delivery address")) }
        data.rider?.let { map.overlays.add(dotMarker(map, it, "#0F766E", data.riderName.ifBlank { "Delivery partner" }, big = true)) }

        val points = listOfNotNull(data.rider, data.drop, data.store)
        if (!fitted && points.isNotEmpty()) {
            if (points.size == 1) {
                map.controller.setCenter(points[0].osm())
            } else {
                val box = BoundingBox.fromGeoPoints(points.map { it.osm() })
                map.post { map.zoomToBoundingBox(box.increaseByScale(1.4f), false) }
            }
            fitted = true
        } else if (data.rider != null) {
            val box = map.boundingBox
            if (!box.contains(data.rider.latitude, data.rider.longitude)) {
                map.controller.animateTo(data.rider.osm())
            }
        }
        map.invalidate()
    }
}

private fun dotMarker(map: MapView, point: GeoPoint, colorHex: String, title: String, big: Boolean = false): Marker {
    val sizePx = (if (big) 26 else 18) * map.resources.displayMetrics.density
    val dot = GradientDrawable().apply {
        shape = GradientDrawable.OVAL
        setColor(AndroidColor.parseColor(colorHex))
        setStroke((3 * map.resources.displayMetrics.density).toInt(), AndroidColor.WHITE)
        setSize(sizePx.toInt(), sizePx.toInt())
    }
    return Marker(map).apply {
        position = point.osm()
        icon = dot
        this.title = title
        setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_CENTER)
    }
}

private fun GeoPoint.osm() = OsmPoint(latitude, longitude)

private fun etaLabel(d: LiveDelivery): String? {
    val rider = d.rider ?: return null
    val drop = d.drop ?: return null
    val km = haversineKm(rider, drop)
    if (km < 0.15) return "Arriving now"
    val speed = (d.riderSpeedMps ?: 0.0).takeIf { it > 1.5 } ?: 5.5
    val minutes = ceil(km * 1000 * 1.3 / speed / 60).toInt().coerceAtLeast(1)
    return "Arriving in ~$minutes min · ${String.format(java.util.Locale.US, "%.1f", km)} km away"
}

private fun freshnessLabel(d: LiveDelivery): String = when {
    d.rider == null -> "Waiting for the delivery partner's location…"
    d.riderAgeSeconds < 15 -> "Location updated just now"
    d.riderAgeSeconds < 120 -> "Location updated ${d.riderAgeSeconds}s ago"
    else -> "Last seen ${d.riderAgeSeconds / 60} min ago — signal may be weak"
}

private fun haversineKm(a: GeoPoint, b: GeoPoint): Double {
    val r = 6371.0
    val dLat = Math.toRadians(b.latitude - a.latitude)
    val dLng = Math.toRadians(b.longitude - a.longitude)
    val h = sin(dLat / 2) * sin(dLat / 2) +
        cos(Math.toRadians(a.latitude)) * cos(Math.toRadians(b.latitude)) * sin(dLng / 2) * sin(dLng / 2)
    return 2 * r * atan2(sqrt(h), sqrt(1 - h))
}

private fun dial(context: Context, phone: String) {
    val digits = phone.filter { it.isDigit() || it == '+' }
    runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$digits"))) }
}

package com.paperlesspharmacy.auraghartak.ui

import android.Manifest
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Link
import androidx.compose.material.icons.outlined.MyLocation
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import kotlinx.coroutines.delay
import androidx.compose.ui.Alignment
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.paperlesspharmacy.auraghartak.data.Branch
import com.paperlesspharmacy.auraghartak.data.Geo
import com.paperlesspharmacy.auraghartak.data.LocationHelper

@Composable
fun StoresScreen(vm: AuraViewModel, onOpen: (Branch) -> Unit, onLink: () -> Unit) {
    val context = LocalContext.current
    val locationHelper = LocationHelper(context.applicationContext)
    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { result ->
        val granted = result[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
            result[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        vm.onLocationPermissionGranted(granted)
    }
    val showAll = vm.showAllPharmacies
    vm.locationAccount
    vm.userLatitude
    vm.userLongitude
    vm.locationMessage
    vm.nearbyRadiusKm
    vm.geocodingStores
    vm.locationSortTick
    vm.storeProductSearching
    LaunchedEffect(vm.storeQuery, showAll, vm.city, vm.locationSortTick, vm.nearbyRadiusKm) {
        delay(if (vm.storeQuery.isBlank()) 0 else 450)
        vm.refreshStoreProductSearch()
    }
    val visibleBranches = vm.filterBranches(showAll)
    val totalCount = vm.branches.size

    Column(Modifier.fillMaxSize().background(ScreenBackgroundBrush)) {
        Column(
            Modifier
                .fillMaxWidth()
                .statusBarsPadding(),
        ) {
            AuraHeroHeader(
                eyebrow = "Paperless Pharmacy",
                title = "AuraGharTak",
                trailing = {
                    Surface(
                        onClick = onLink,
                        shape = RoundedCornerShape(999.dp),
                        color = Color.White.copy(alpha = 0.16f),
                        border = BorderStroke(1.dp, Color.White.copy(alpha = 0.35f)),
                    ) {
                        Row(
                            Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Outlined.Link, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("Pharmacy link", color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 12.sp)
                        }
                    }
                },
            ) {
                SearchField(vm.storeQuery, "Pharmacy, city, or medicine", onChange = { vm.storeQuery = it })
            }
        }

        Row(
            Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = AuraDimens.screenPad, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            SelectableChip("All areas", vm.city.isBlank()) { vm.selectCity("") }
            vm.cities.forEach { name ->
                SelectableChip(name, vm.city.equals(name, true)) { vm.selectCity(name) }
            }
        }

        if (vm.hasUserLocation || totalCount > 0) {
            Surface(
                shape = MaterialTheme.shapes.medium,
                color = Color.White,
                border = BorderStroke(1.dp, Line.copy(alpha = 0.8f)),
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = AuraDimens.screenPad),
            ) {
                Column(Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            Icons.Outlined.MyLocation,
                            contentDescription = null,
                            tint = Teal,
                            modifier = Modifier.size(16.dp),
                        )
                        Spacer(Modifier.width(6.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                if (showAll) "All pharmacies" else "Near you",
                                fontSize = 13.sp,
                                fontWeight = FontWeight.SemiBold,
                                color = Ink,
                                maxLines = 1,
                            )
                            Text(
                                when {
                                    vm.geocodingStores -> "Calculating distances…"
                                    !vm.locationMessage.isNullOrBlank() -> vm.locationMessage.orEmpty()
                                    vm.nearYouSummary.isNotBlank() -> vm.nearYouSummary
                                    showAll -> "Every pharmacy on AuraGharTak"
                                    vm.hasGpsLocation -> "Nearest first"
                                    else -> "Use GPS or set city / PIN in Profile"
                                },
                                fontSize = 11.sp,
                                color = Mute,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                        }
                        Spacer(Modifier.width(6.dp))
                        TextActionChip(
                            when {
                                vm.locationLoading -> "Locating…"
                                vm.hasGpsLocation -> "GPS ✓"
                                else -> "Use GPS"
                            },
                            active = vm.hasGpsLocation,
                        ) {
                            if (locationHelper.canLocate()) {
                                vm.refreshUserLocation()
                            } else {
                                permissionLauncher.launch(
                                    arrayOf(
                                        Manifest.permission.ACCESS_FINE_LOCATION,
                                        Manifest.permission.ACCESS_COARSE_LOCATION,
                                    ),
                                )
                            }
                        }
                        Spacer(Modifier.width(4.dp))
                        TextActionChip(
                            if (showAll) "Nearby" else "Show all",
                            active = showAll,
                        ) {
                            if (showAll) vm.setNearbyOnly() else vm.toggleShowAllPharmacies()
                        }
                    }
                    if (vm.hasGpsLocation && !showAll) {
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .horizontalScroll(rememberScrollState())
                                .padding(top = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text("Within", fontSize = 11.sp, color = Mute)
                            Geo.radiusOptionsKm.forEach { km ->
                                SelectableChip(
                                    label = "$km km",
                                    selected = vm.nearbyRadiusKm.toInt() == km,
                                ) { vm.selectNearbyRadius(km) }
                            }
                        }
                    }
                }
            }
        }

        Text(
            when {
                vm.storesLoading -> "Finding pharmacies…"
                vm.storeProductSearching && vm.storeQuery.trim().length >= 2 ->
                    "Searching stock for “${vm.storeQuery.trim()}”…"
                showAll -> "${visibleBranches.size} pharmacies · all areas"
                vm.hasGpsLocation -> {
                    val r = vm.nearbyRadiusKm.toInt()
                    if (visibleBranches.size < totalCount) {
                        "${visibleBranches.size} of $totalCount · within $r km"
                    } else {
                        "${visibleBranches.size} within $r km · nearest first"
                    }
                }
                !vm.hasUserLocation -> "${visibleBranches.size} pharmacies · all areas"
                visibleBranches.size < totalCount -> "${visibleBranches.size} of $totalCount · near you"
                else -> "${visibleBranches.size} pharmacies · near you"
            },
            fontSize = 11.sp,
            color = Mute,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.padding(horizontal = AuraDimens.screenPad, vertical = 6.dp),
        )

        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                vm.storesError != null && vm.branches.isEmpty() -> {
                    Column(Modifier.padding(AuraDimens.screenPad)) {
                        ErrorBanner(vm.storesError ?: "", onRetry = vm::loadStores)
                    }
                }
                vm.storesLoading && vm.branches.isEmpty() -> LoadingState("Loading pharmacies…")
                visibleBranches.isEmpty() -> EmptyHint(
                    "No pharmacy in this list",
                    when {
                        vm.storeQuery.trim().length >= 2 && vm.storeProductSearching ->
                            "Checking nearby pharmacies for matching medicines…"
                        vm.storeQuery.trim().length >= 2 && !vm.storeProductSearching ->
                            "No in-stock match for “${vm.storeQuery.trim()}”. Try “Show all”, pick a city chip (e.g. Pune), or check spelling."
                        !showAll && vm.hasGpsLocation -> {
                            val r = vm.nearbyRadiusKm.toInt()
                            if (vm.geocodingStores) {
                                "Still locating pharmacies… try again in a moment, or widen to 25–50 km."
                            } else {
                                "No pharmacy within $r km. Try 25 or 50 km, or tap “Show all”."
                            }
                        }
                        !showAll && vm.nearYouSummary.isNotBlank() ->
                            "Tap “Show all” to see pharmacies across India, or update city / PIN in Profile."
                        else ->
                            "Try another city, or ask your pharmacy to send you an AuraGharTak link."
                    },
                )
                else -> {
                    BoxWithConstraints(Modifier.fillMaxSize()) {
                        // Phones: one full-width row per pharmacy (names stay readable). Tablets: grid.
                        val columns = when {
                            maxWidth < 560.dp -> 1
                            maxWidth < 840.dp -> 2
                            else -> 3
                        }
                        LazyVerticalGrid(
                            columns = GridCells.Fixed(columns),
                            contentPadding = PaddingValues(
                                start = AuraDimens.screenPad,
                                end = AuraDimens.screenPad,
                                top = 2.dp,
                                bottom = AuraDimens.spaceMd,
                            ),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                            verticalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            items(visibleBranches, key = { "${it.id}-${it.source}" }) { branch ->
                                PharmacyRow(
                                    branch,
                                    vm.branchNearLabel(branch),
                                    vm.storeProductHint(branch),
                                ) { onOpen(branch) }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PharmacyRow(branch: Branch, nearLabel: String?, productHint: String?, onOpen: () -> Unit) {
    Surface(
        onClick = onOpen,
        shape = MaterialTheme.shapes.medium,
        color = Color.White,
        shadowElevation = 1.dp,
        border = BorderStroke(1.dp, Line.copy(alpha = 0.7f)),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            Modifier.padding(horizontal = 10.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Avatar(branch.title, size = 38.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    branch.title,
                    fontSize = 14.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = Ink,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    lineHeight = 18.sp,
                )
                Text(
                    branch.subtitle.ifBlank { branch.city.ifBlank { "Home delivery" } },
                    fontSize = 11.sp,
                    color = Mute,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                val badge = nearLabel ?: if (branch.source == "retail") "Retail" else null
                val weakHint = branch.source == "retail" && branch.isWeakRetailLocation() && nearLabel == null
                if (badge != null || weakHint) {
                    Row(
                        Modifier.padding(top = 3.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (badge != null) {
                            Text(
                                badge,
                                color = TealDeep,
                                fontSize = 10.sp,
                                fontWeight = FontWeight.Bold,
                                maxLines = 1,
                                modifier = Modifier
                                    .clip(RoundedCornerShape(999.dp))
                                    .background(if (nearLabel != null) Mint else MintSoft)
                                    .padding(horizontal = 7.dp, vertical = 2.dp),
                            )
                        }
                        if (weakHint) {
                            Text(
                                "Set GPS for distance",
                                fontSize = 10.sp,
                                color = Mute,
                                maxLines = 1,
                                modifier = Modifier.padding(start = 6.dp),
                            )
                        }
                    }
                }
                if (!productHint.isNullOrBlank()) {
                    Text(
                        productHint,
                        color = TealDeep,
                        fontSize = 11.sp,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                }
            }
            Spacer(Modifier.width(8.dp))
            Surface(shape = RoundedCornerShape(999.dp), color = Teal) {
                Text(
                    "Order",
                    color = Color.White,
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 12.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 6.dp),
                )
            }
        }
    }
}

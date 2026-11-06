package com.paperlesspharmacy.auraghartak.ui

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.util.Base64
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.paperlesspharmacy.auraghartak.data.AgtUserAccount
import com.paperlesspharmacy.auraghartak.data.AgtCartLine
import com.paperlesspharmacy.auraghartak.data.AgtPlaceResult
import com.paperlesspharmacy.auraghartak.data.AgtProduct
import com.paperlesspharmacy.auraghartak.data.AgtShop
import com.paperlesspharmacy.auraghartak.data.ApiException
import com.paperlesspharmacy.auraghartak.data.Branch
import com.paperlesspharmacy.auraghartak.data.CartLine
import com.paperlesspharmacy.auraghartak.data.CatalogItem
import com.paperlesspharmacy.auraghartak.data.OrderDetail
import com.paperlesspharmacy.auraghartak.data.OrderEvent
import com.paperlesspharmacy.auraghartak.data.OrderSummary
import com.paperlesspharmacy.auraghartak.data.RetailOrderSummary
import com.paperlesspharmacy.auraghartak.data.PharmaApi
import com.paperlesspharmacy.auraghartak.data.RetailApi
import com.paperlesspharmacy.auraghartak.data.RetailLocationOverrides
import com.paperlesspharmacy.auraghartak.data.CityFilter
import com.paperlesspharmacy.auraghartak.data.ProductSearchMatch
import com.paperlesspharmacy.auraghartak.data.PaymentMethods
import com.paperlesspharmacy.auraghartak.data.PaymentOption
import com.paperlesspharmacy.auraghartak.data.Profile
import com.paperlesspharmacy.auraghartak.data.SavedOrder
import com.paperlesspharmacy.auraghartak.data.TrackResult
import com.paperlesspharmacy.auraghartak.notify.NotificationHelper
import com.paperlesspharmacy.auraghartak.notify.OrderStatusSync
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import com.paperlesspharmacy.auraghartak.data.BranchLocations
import com.paperlesspharmacy.auraghartak.data.Geo
import com.paperlesspharmacy.auraghartak.data.GeoCachePrefs
import com.paperlesspharmacy.auraghartak.data.PinProximity
import com.paperlesspharmacy.auraghartak.data.LocationHelper
import com.paperlesspharmacy.auraghartak.data.ResolvedPlace
import com.paperlesspharmacy.auraghartak.data.NearYou
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withPermit
import com.paperlesspharmacy.auraghartak.data.EngagementPrefs
import com.paperlesspharmacy.auraghartak.data.FavoriteMed
import com.paperlesspharmacy.auraghartak.data.RefillReminder
import com.paperlesspharmacy.auraghartak.data.SavedOrderLine
import com.paperlesspharmacy.auraghartak.data.StockAlert
import com.paperlesspharmacy.auraghartak.data.SubstituteItem
import com.paperlesspharmacy.auraghartak.data.UserPrefs
import com.paperlesspharmacy.auraghartak.data.openWhatsAppChat
import kotlinx.coroutines.Dispatchers.Main
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream

class AuraViewModel(app: Application) : AndroidViewModel(app) {
    private val api = PharmaApi()
    private val retailApi = RetailApi()
    private val prefs = UserPrefs(app)
    private val engagement = EngagementPrefs(app)
    private val locationHelper = LocationHelper(app)

    val deliverySlots = listOf(
        "ASAP (next 2 hours)",
        "Today morning (9–12)",
        "Today afternoon (12–5)",
        "Today evening (5–9)",
        "Tomorrow morning",
        "Tomorrow evening",
    )
    var deliverySlot by mutableStateOf(deliverySlots[0])
    val paymentOptions: List<PaymentOption> = PaymentMethods.options
    var paymentMethod by mutableStateOf(PaymentMethods.options.first().id)
    var storeRxLabel by mutableStateOf<String?>(null)
        private set
    private var storeRxBase64: String? = null
    var notificationsEnabled by mutableStateOf(engagement.notificationsEnabled())
        private set
    var favorites by mutableStateOf(engagement.favorites())
        private set
    var stockAlerts by mutableStateOf(engagement.stockAlerts())
        private set
    var refills by mutableStateOf(engagement.refills())
        private set
    var substitutes by mutableStateOf<List<SubstituteItem>>(emptyList())
        private set
    var substitutesLoading by mutableStateOf(false)
        private set
    var substitutesForName by mutableStateOf("")
        private set

    var profile by mutableStateOf(prefs.profile())
        private set
    /** Drives "near you" sorting/filtering; kept in Compose state so toggles refresh the list. */
    var locationAccount by mutableStateOf(prefs.account())
        private set
    var city by mutableStateOf(prefs.city())
        private set
    /** When false, list only pharmacies matching your PIN / city / state (sorted nearest first). */
    var showAllPharmacies by mutableStateOf(false)
    var storeQuery by mutableStateOf("")
    var branches by mutableStateOf<List<Branch>>(emptyList())
        private set
    var storesLoading by mutableStateOf(false)
        private set
    var storesError by mutableStateOf<String?>(null)
        private set
    var userLatitude by mutableStateOf<Double?>(null)
        private set
    var userLongitude by mutableStateOf<Double?>(null)
        private set
    var locationLoading by mutableStateOf(false)
        private set
    var locationMessage by mutableStateOf<String?>(null)
        private set
    private var gpsPlace by mutableStateOf<ResolvedPlace?>(null)
    /** Max distance (km) when filtering by GPS; options 1, 5, 10, 25, 50. */
    var nearbyRadiusKm by mutableStateOf(Geo.DEFAULT_RADIUS_KM)
    var geocodingStores by mutableStateOf(false)
        private set
    private val geocodeCache = mutableMapOf<String, Pair<Double, Double>>()
    private val geoCachePrefs = GeoCachePrefs(app)
    private val pinCoordCache = geoCachePrefs.loadPins().toMutableMap()
    var locationSortTick by mutableStateOf(0)
        private set
    private var geocodeJobGen = 0
    private var filterBranchesCacheKey: Long = Long.MIN_VALUE
    private var filterBranchesCache: List<Branch> = emptyList()
    var storeProductSearching by mutableStateOf(false)
        private set
    private var storeProductHits by mutableStateOf<Map<String, List<String>>>(emptyMap())
    private var storeProductSearchTick by mutableStateOf(0)
    private var storeProductSearchGen = 0

    var branch by mutableStateOf<Branch?>(null)
        private set
    var pendingBranch by mutableStateOf<Branch?>(null)

    /** Shown as a popup when the customer tries to add more than the pharmacy has in stock. */
    var stockLimitNotice by mutableStateOf<String?>(null)
    var catalogQuery by mutableStateOf("")
    var catalog by mutableStateOf<List<CatalogItem>>(emptyList())
        private set
    var catalogLoading by mutableStateOf(false)
        private set
    var catalogError by mutableStateOf<String?>(null)
        private set
    val cart = mutableStateListOf<CartLine>()
    var note by mutableStateOf("")
    var placing by mutableStateOf(false)
        private set
    var formError by mutableStateOf<String?>(null)
    var lastOrder by mutableStateOf<SavedOrder?>(null)
        private set

    var lookupPhone by mutableStateOf(prefs.profile().phone)
    var orders by mutableStateOf(prefs.orders())
        private set
    var ordersLoading by mutableStateOf(false)
        private set
    var ordersError by mutableStateOf<String?>(null)
        private set
    var selectedOrder by mutableStateOf<SavedOrder?>(null)
        private set
    var detail by mutableStateOf<OrderDetail?>(null)
        private set
    var detailLoading by mutableStateOf(false)
        private set
    var detailError by mutableStateOf<String?>(null)
    var cancelling by mutableStateOf(false)
        private set

    var agtTokenInput by mutableStateOf("")
    var agtShop by mutableStateOf<AgtShop?>(null)
        private set
    var agtQuery by mutableStateOf("")
    var agtLoading by mutableStateOf(false)
        private set
    var agtError by mutableStateOf<String?>(null)
    val agtCart = mutableStateListOf<AgtCartLine>()
    var agtNote by mutableStateOf("")
    var agtRxLabel by mutableStateOf<String?>(null)
    var agtPlacing by mutableStateOf(false)
        private set
    var agtDone by mutableStateOf<AgtPlaceResult?>(null)
        private set
    private var agtRxBase64: String? = null
    private var catalogGen = 0
    private var agtGen = 0

    val cities: List<String>
        get() = CityFilter.chipsFromBranches(branches, userCity)

    val userCity: String
        get() = locationAccount?.city?.trim().orEmpty()

    val userPincode: String
        get() = locationAccount?.pincode?.trim().orEmpty().ifBlank { profile.pincode.trim() }

    val userState: String
        get() = locationAccount?.state?.trim().orEmpty()

    val hasGpsLocation: Boolean
        get() = userLatitude != null && userLongitude != null

    val hasUserLocation: Boolean
        get() = nearYouFilterContext().let { (c, p, s) ->
            NearYou.hasUserLocation(c, p, s)
        }

    val nearYouSummary: String
        get() {
            locationMessage?.takeIf { it.isNotBlank() }?.let { return it }
            val (c, p, s) = nearYouFilterContext()
            val parts = mutableListOf<String>()
            if (hasGpsLocation) parts += "Your GPS area"
            if (c.isNotBlank()) parts += c
            val pin = NearYou.pinDigits(p)
            if (pin.length >= 6) parts += "PIN $pin"
            else if (pin.length >= 3) parts += "PIN ${pin}…"
            if (s.isNotBlank() && !parts.any { it.equals(s, ignoreCase = true) }) parts += s
            return parts.joinToString(" · ").ifBlank { "Add city / PIN in Profile or use GPS" }
        }

    private fun pinLatLng(branch: Branch): Pair<Double, Double>? {
        val pin = branch.pinForDistance()
        if (pin.length != 6) return null
        return pinCoordCache[pin]
    }

    private fun branchDistance(branch: Branch, uLat: Double, uLng: Double): Double? {
        branch.distanceKmFrom(uLat, uLng, pinLatLng(branch))?.let { return it }
        val userPin = nearYouFilterContext().second
        val bp = branch.pinForDistance()
        if (bp.length != 6) return null
        if (!PinProximity.samePinArea(userPin, bp)) return null
        return PinProximity.estimateKm(userPin, bp)
    }

    private fun passesGpsNearbyFilter(branch: Branch, distanceKm: Double?): Boolean {
        if (distanceKm == null || distanceKm > nearbyRadiusKm) return false
        if (branch.isWeakRetailLocation()) return false
        val userPin = nearYouFilterContext().second
        val bp = branch.pinForDistance()
        if (bp.length == 6 && NearYou.pinDigits(userPin).length == 6 &&
            !PinProximity.samePinArea(userPin, bp)
        ) {
            return branch.hasCoordinates() || pinLatLng(branch) != null
        }
        return true
    }

    /** City / PIN / state used to match pharmacy addresses (GPS area first, else profile). */
    private fun nearYouFilterContext(): Triple<String, String, String> {
        gpsPlace?.takeIf { it.hasUsableArea() }?.let {
            return Triple(it.city, it.pincode, it.state)
        }
        return Triple(userCity, userPincode, userState)
    }

    private fun branchRowKey(branch: Branch): String = "${branch.source}:${branch.locationKey()}"

    private fun filterBranchesCacheSignature(forShowAll: Boolean): Long {
        val (uc, up, us) = nearYouFilterContext()
        var h = 17L
        h = 31 * h + locationSortTick
        h = 31 * h + branches.size
        h = 31 * h + (if (forShowAll) 1 else 0)
        h = 31 * h + storeQuery.hashCode()
        h = 31 * h + storeProductSearchTick
        h = 31 * h + city.hashCode()
        h = 31 * h + nearbyRadiusKm.hashCode()
        h = 31 * h + (userLatitude?.hashCode() ?: 0)
        h = 31 * h + (userLongitude?.hashCode() ?: 0)
        h = 31 * h + uc.hashCode()
        h = 31 * h + up.hashCode()
        h = 31 * h + us.hashCode()
        return h
    }

    private fun branchMatchesPharmacyText(branch: Branch, query: String): Boolean {
        if (query.isBlank()) return true
        val text = "${branch.businessName} ${branch.name} ${branch.city} ${branch.state} " +
            "${branch.pincode} ${branch.clientNo} ${branch.address}"
        return text.contains(query, ignoreCase = true)
    }

    private fun branchHasProductHit(branch: Branch): Boolean =
        !storeProductHits[branchRowKey(branch)].isNullOrEmpty()

    private fun branchesMatchingCityChip(): List<Branch> =
        branches.filter { CityFilter.branchMatches(city, it) }

    private fun isMedicineSearch(query: String): Boolean =
        query.trim().length >= 2 && ProductSearchMatch.tokens(query).isNotEmpty()

    /** Sort by distance / near-you score (optional radius filter). */
    private fun sortAndFilterByLocation(
        pool: List<Branch>,
        forShowAll: Boolean,
        applyNearbyRadius: Boolean,
    ): List<Branch> {
        val (uc, up, us) = nearYouFilterContext()
        val uLat = userLatitude
        val uLng = userLongitude
        if (Geo.isValidCoord(uLat, uLng)) {
            val rows = pool.map { b ->
                val d = branchDistance(b, uLat!!, uLng!!)
                val score = NearYou.score(uc, up, us, b)
                Triple(b, d, score)
            }
            val narrowed = if (forShowAll || !applyNearbyRadius) {
                rows
            } else {
                rows.filter { (b, d, score) ->
                    if (passesGpsNearbyFilter(b, d)) return@filter true
                    if (d != null) return@filter false
                    if (!NearYou.passesPinNearbyFilter(up, b)) return@filter false
                    val max = NearYou.maxNearbyScoreForBranch(uc, up, us, b)
                    score <= max && score <= NearYou.SCORE_PIN_AREA
                }
            }
            return narrowed
                .sortedWith(
                    compareBy<Triple<Branch, Double?, Int>>(
                        { it.second ?: Double.MAX_VALUE },
                        { it.third },
                        { it.first.businessName.lowercase() },
                    ),
                )
                .map { it.first }
        }
        val located = NearYou.hasUserLocation(uc, up, us)
        val scored = pool.map { b -> b to NearYou.score(uc, up, us, b) }
        val narrowed = when {
            forShowAll || !applyNearbyRadius || !located -> scored
            else -> scored.filter { (b, score) ->
                if (!NearYou.passesPinNearbyFilter(up, b)) return@filter false
                val max = NearYou.maxNearbyScoreForBranch(uc, up, us, b)
                score <= max
            }
        }
        return narrowed.sortedWith(compareBy({ it.second }, { it.first.businessName.lowercase() }))
            .map { it.first }
    }

    /** City + near-you filter and sort (no dashboard search text). */
    private fun branchesByLocation(forShowAll: Boolean): List<Branch> {
        val browseByCity = city.isNotBlank()
        val pool = branchesMatchingCityChip()
        val effectiveShowAll = forShowAll || browseByCity
        return sortAndFilterByLocation(pool, effectiveShowAll, applyNearbyRadius = !browseByCity)
    }

    /** Medicine search scans city/show-all pool (not km radius). */
    private fun branchesForProductScan(forShowAll: Boolean): List<Branch> {
        val pool = when {
            city.isNotBlank() -> branchesMatchingCityChip()
            forShowAll -> branches
            else -> sortAndFilterByLocation(branchesMatchingCityChip(), forShowAll = false, applyNearbyRadius = true)
        }
        return sortAndFilterByLocation(pool, forShowAll = true, applyNearbyRadius = false)
    }

    fun filterBranches(forShowAll: Boolean = showAllPharmacies): List<Branch> {
        val sig = filterBranchesCacheSignature(forShowAll)
        if (sig == filterBranchesCacheKey) return filterBranchesCache
            val q = storeQuery.trim()
        val located = branchesByLocation(forShowAll)
        val out = if (q.isBlank()) {
            located
        } else {
            val medicine = isMedicineSearch(q)
            val pool = if (medicine) branchesForProductScan(forShowAll) else located
            pool.filter { b -> branchMatchesPharmacyText(b, q) || branchHasProductHit(b) }
                .sortedWith(
                    compareByDescending<Branch> { branchHasProductHit(it) }
                        .thenComparing { b ->
                            located.indexOf(b).takeIf { it >= 0 } ?: Int.MAX_VALUE
                        }
                        .thenComparing { it.businessName.lowercase() },
                )
        }
        filterBranchesCacheKey = sig
        filterBranchesCache = out
        return out
    }

    fun storeProductHint(branch: Branch): String? {
        val names = storeProductHits[branchRowKey(branch)] ?: return null
        if (names.isEmpty()) return null
        val preview = names.take(2).joinToString(", ")
        val suffix = if (names.size > 2) " +${names.size - 2} more" else ""
        return "Has: $preview$suffix"
    }

    fun refreshStoreProductSearch() {
        val q = storeQuery.trim()
        if (q.length < 2) {
            storeProductHits = emptyMap()
            storeProductSearchTick++
            storeProductSearching = false
            return
        }
        val gen = ++storeProductSearchGen
        viewModelScope.launch {
            storeProductSearching = true
            try {
                val cap = when {
                    city.isNotBlank() -> 200
                    showAllPharmacies -> 200
                    else -> 80
                }
                val candidates = branchesForProductScan(showAllPharmacies).take(cap)
                val gate = Semaphore(8)
                val hits = coroutineScope {
                    candidates.map { branch ->
                        async(Dispatchers.IO) {
                            gate.withPermit {
                                if (gen != storeProductSearchGen) return@withPermit null
                                val names = when (branch.source) {
                                    "retail" -> retailApi.searchProductNames(branch.clientNo, q)
                                    else -> api.searchProductNames(branch.id, q)
                                }
                                if (names.isEmpty()) null else branchRowKey(branch) to names
                            }
                        }
                    }.awaitAll().filterNotNull().toMap()
                }
                if (gen == storeProductSearchGen) {
                    storeProductHits = hits
                    storeProductSearchTick++
                }
            } finally {
                if (gen == storeProductSearchGen) storeProductSearching = false
            }
        }
    }

    fun branchNearLabel(branch: Branch): String? {
        val uLat = userLatitude
        val uLng = userLongitude
        if (Geo.isValidCoord(uLat, uLng)) {
            val exact = branch.distanceKmFrom(uLat!!, uLng!!, pinLatLng(branch))
            if (exact != null) return Geo.formatDistanceKm(exact)
            val est = PinProximity.estimateKm(nearYouFilterContext().second, branch.pinForDistance())
            if (est != null && est < 400) return "~${Geo.formatDistanceKm(est)}"
        }
        val (uc, up, us) = nearYouFilterContext()
        return NearYou.label(NearYou.score(uc, up, us, branch))
    }

    fun branchDistanceKm(branch: Branch): Double? {
        val uLat = userLatitude ?: return null
        val uLng = userLongitude ?: return null
        return branchDistance(branch, uLat, uLng)
    }

    fun refreshUserLocation() {
        if (!locationHelper.canLocate()) {
            locationMessage = "Allow location to sort by distance"
            return
        }
        viewModelScope.launch {
            locationLoading = true
            locationMessage = null
            try {
                val pair = withContext(Dispatchers.IO) { locationHelper.currentLatLng() }
                if (pair == null) {
                    locationMessage = "Could not read GPS — try again outdoors"
                    userLatitude = null
                    userLongitude = null
                    gpsPlace = null
                } else {
                    userLatitude = pair.first
                    userLongitude = pair.second
                    val place = withContext(Dispatchers.IO) {
                        locationHelper.resolvePlace(pair.first, pair.second)
                    }
                    gpsPlace = place
                    locationMessage = "Within ${nearbyRadiusKm.toInt()} km · nearest first"
                    showAllPharmacies = false
                    locationSortTick++
                    enrichBranchCoordinates()
                }
            } finally {
                locationLoading = false
            }
        }
    }

    fun selectNearbyRadius(km: Int) {
        nearbyRadiusKm = km.toDouble()
        showAllPharmacies = false
        locationMessage = if (hasGpsLocation) {
            "Within $km km of you"
        } else {
            locationMessage
        }
    }

    private suspend fun warmPinGeocodes(branchList: List<Branch>, userPin: String) {
        val user = NearYou.pinDigits(userPin)
        val pins = branchList.map { it.pinForDistance() }
            .filter { it.length == 6 }
            .distinct()
            .sortedBy { pin ->
                if (user.length == 6) PinProximity.estimateKm(user, pin) ?: 999.0 else 0.0
            }
            .take(20)
        val need = pins.filter { !pinCoordCache.containsKey(it) }
        if (need.isEmpty()) return
        val gate = Semaphore(5)
        coroutineScope {
            need.map { pin ->
                async(Dispatchers.IO) {
                    gate.withPermit {
                        locationHelper.geocodePincode(pin)?.let { pin to it }
                    }
                }
            }.awaitAll().filterNotNull().forEach { (pin, coords) ->
                pinCoordCache[pin] = coords
            }
        }
        geoCachePrefs.savePins(pinCoordCache)
    }

    private fun enrichBranchCoordinates() {
        if (!hasGpsLocation) return
        val gen = ++geocodeJobGen
        val userPin = nearYouFilterContext().second.ifBlank { userPincode }
        locationSortTick++
        viewModelScope.launch {
            geocodingStores = true
            try {
                warmPinGeocodes(branches, userPin)
                locationSortTick++
                val uLat = userLatitude ?: return@launch
                val uLng = userLongitude ?: return@launch
                val place = gpsPlace
                val missing = branches
                    .filter { b -> !b.isWeakRetailLocation() }
                    .filter { b -> !b.hasCoordinates() && pinLatLng(b) == null }
                    .filter { b -> b.geocodeQuery(place).isNotBlank() }
                    .sortedBy { PinProximity.estimateKm(userPin, it.pinForDistance()) ?: 999.0 }
                    .take(12)
                if (missing.isEmpty() || gen != geocodeJobGen) return@launch
                val radiusCap = nearbyRadiusKm.coerceAtLeast(25.0) * 1.5
                val gate = Semaphore(4)
                val coordByBranch = coroutineScope {
                    missing.map { branch ->
                        async(Dispatchers.IO) {
                            gate.withPermit {
                                if (gen != geocodeJobGen) return@withPermit null
                                val query = branch.geocodeQuery(place)
                                val cacheKey = "${branch.source}:${branch.locationKey()}:$query"
                                val coords = geocodeCache[cacheKey]
                                    ?: locationHelper.geocodeAddress(query)?.also { geocodeCache[cacheKey] = it }
                                    ?: return@withPermit null
                                if (Geo.isValidCoord(uLat, uLng)) {
                                    val km = Geo.distanceKm(uLat, uLng, coords.first, coords.second)
                                    if (km > radiusCap) return@withPermit null
                                }
                                branchRowKey(branch) to coords
                            }
                        }
                    }.awaitAll().filterNotNull().toMap()
                }
                if (gen != geocodeJobGen || coordByBranch.isEmpty()) return@launch
                branches = branches.map { b ->
                    coordByBranch[branchRowKey(b)]?.let { (lat, lng) ->
                        BranchLocations.applyCoords(b, lat, lng)
                    } ?: b
                }
                locationSortTick++
            } finally {
                if (gen == geocodeJobGen) {
                    geocodingStores = false
                    locationSortTick++
                }
            }
        }
    }

    fun onLocationPermissionGranted(granted: Boolean) {
        if (granted) refreshUserLocation() else {
            locationMessage = "Location off — using profile city / PIN"
            userLatitude = null
            userLongitude = null
            gpsPlace = null
        }
    }

    fun toggleShowAllPharmacies() {
        showAllPharmacies = !showAllPharmacies
        if (showAllPharmacies) {
            selectCity("")
        }
    }

    fun setNearbyOnly() {
        showAllPharmacies = false
        }

    fun cartTotal(): Double = cart.sumOf { it.rate * it.qty }
    fun agtCartTotal(): Double = agtCart.sumOf { it.unitMrp * it.qty }
    fun qtyOf(bindingId: Long): Int = cart.find { it.bindingId == bindingId }?.qty ?: 0
    fun agtQtyOf(name: String, batch: String): Int =
        agtCart.find { it.name == name && it.batchNo == batch }?.qty ?: 0

    fun loadStores() {
        viewModelScope.launch {
            storesLoading = true
            storesError = null
            try {
                val advanced = runCatching { withContext(Dispatchers.IO) { api.branches() } }
                val retail = runCatching { withContext(Dispatchers.IO) { retailApi.clients() } }
                val locations = runCatching { withContext(Dispatchers.IO) { api.storeLocations() } }
                    .getOrDefault(emptyList())
                val retailRows = RetailLocationOverrides.enrichAll(retail.getOrDefault(emptyList()))
                val merged = BranchLocations.mergeApi(
                    advanced.getOrDefault(emptyList()) + retailRows,
                    locations,
                )
                if (retailRows.isNotEmpty()) {
                    runCatching {
                        withContext(Dispatchers.IO) {
                            api.syncRetailLocationStubs(retailRows.map { it.clientNo })
                        }
                    }
                }
                val retailErr = retail.exceptionOrNull()
                if (merged.isEmpty()) {
                    storesError = advanced.exceptionOrNull()?.message
                        ?: retailErr?.message
                        ?: "No pharmacies found."
                } else if (retailErr != null && retail.getOrDefault(emptyList()).isEmpty()) {
                    locationMessage = "Retail list unavailable — showing Advanced pharmacies only."
                }
                branches = merged
                locationSortTick++
                if (locationHelper.canLocate() && !hasGpsLocation) {
                    refreshUserLocation()
                } else if (hasGpsLocation) {
                    enrichBranchCoordinates()
                }
            } catch (e: ApiException) {
                storesError = e.message
            } finally {
                storesLoading = false
            }
        }
    }

    fun selectCity(value: String) {
        city = value.trim()
        prefs.saveCity(city)
        locationSortTick++
        filterBranchesCacheKey = Long.MIN_VALUE
        if (storeQuery.trim().length >= 2) refreshStoreProductSearch()
    }

    fun selectBranch(next: Branch): Boolean {
        if (cart.isNotEmpty() && branch?.id != next.id) {
            pendingBranch = next
            return false
        }
        applyBranch(next)
        return true
    }

    fun confirmPendingBranch(): Branch? {
        val next = pendingBranch ?: return null
        pendingBranch = null
        applyBranch(next)
        return next
    }

    private fun applyBranch(next: Branch) {
        if (branch?.id != next.id) cart.clear()
        branch = next
        val dashQ = storeQuery.trim()
        catalogQuery = if (dashQ.length >= 2) dashQ else ""
        catalog = emptyList()
        catalogError = null
        formError = null
    }

    fun loadCatalog() {
        val current = branch ?: return
        val q = catalogQuery
        val gen = ++catalogGen
        viewModelScope.launch {
            catalogLoading = true
            catalogError = null
            try {
                val rows = withContext(Dispatchers.IO) {
                    if (current.source == "retail") retailApi.products(current.clientNo, q)
                    else api.catalog(current.id, q)
                }
                if (gen == catalogGen) {
                    catalog = rows
                    checkStockAlerts(current, rows)
                }
            } catch (e: ApiException) {
                if (gen == catalogGen) catalogError = e.message
            } finally {
                if (gen == catalogGen) catalogLoading = false
            }
        }
    }

    fun isFavorite(item: CatalogItem): Boolean {
        val b = branch ?: return false
        return favorites.any { it.branchId == b.id && it.bindingId == item.bindingId }
    }

    fun toggleFavorite(item: CatalogItem) {
        val b = branch ?: return
        val key = { f: FavoriteMed -> f.branchId == b.id && f.bindingId == item.bindingId }
        val next = if (favorites.any(key)) {
            favorites.filterNot(key)
        } else {
            favorites + FavoriteMed(b.id, b.title, item.bindingId, item.productName, item.mrp)
        }
        favorites = next
        engagement.saveFavorites(next)
    }

    fun addStockAlertForQuery() {
        val b = branch ?: return
        val name = catalogQuery.trim()
        if (name.length < 2) return
        val alert = StockAlert(
            branchId = b.id,
            branchName = b.title,
            bindingId = 0,
            productName = name,
            createdAt = java.time.LocalDate.now().toString(),
        )
        if (stockAlerts.any { it.branchId == b.id && it.productName.equals(name, true) }) return
        val next = listOf(alert) + stockAlerts
        stockAlerts = next
        engagement.saveStockAlerts(next)
    }

    fun addStockAlert(item: CatalogItem) {
        val b = branch ?: return
        val alert = StockAlert(b.id, b.title, item.bindingId, item.productName, java.time.LocalDate.now().toString())
        if (stockAlerts.any { it.branchId == b.id && it.bindingId == item.bindingId }) return
        val next = listOf(alert) + stockAlerts
        stockAlerts = next
        engagement.saveStockAlerts(next)
    }

    fun removeStockAlert(alert: StockAlert) {
        val next = stockAlerts.filterNot { it.branchId == alert.branchId && it.bindingId == alert.bindingId && it.productName == alert.productName }
        stockAlerts = next
        engagement.saveStockAlerts(next)
    }

    fun loadSubstitutes(bindingId: Long, productName: String) {
        val b = branch ?: return
        if (b.source == "retail") return
        substitutesForName = productName
        viewModelScope.launch {
            substitutesLoading = true
            substitutes = emptyList()
            try {
                substitutes = withContext(Dispatchers.IO) { api.catalogSubstitutes(b.id, bindingId) }
            } catch (_: ApiException) {
                substitutes = emptyList()
            } finally {
                substitutesLoading = false
            }
        }
    }

    fun clearSubstitutes() {
        substitutes = emptyList()
        substitutesForName = ""
    }

    fun addSubstituteToCart(sub: SubstituteItem) {
        changeQty(
            CatalogItem(
                sub.bindingId, sub.productName, sub.scheduleCategory, sub.availableQty, sub.mrp,
                unitsPerPack = sub.unitsPerPack, packSize = sub.packSize,
            ),
            1,
        )
        clearSubstitutes()
    }

    fun updateNotificationsEnabled(on: Boolean) {
        notificationsEnabled = on
        engagement.setNotificationsEnabled(on)
    }

    fun addRefill(medicineName: String, everyDays: Int) {
        val name = medicineName.trim()
        if (name.isBlank() || everyDays < 1) return
        val due = java.time.LocalDate.now().plusDays(everyDays.toLong())
        val fmt = java.time.format.DateTimeFormatter.ofPattern("dd-MM-yyyy")
        val item = RefillReminder(
            id = "${System.currentTimeMillis()}",
            medicineName = name,
            everyDays = everyDays,
            nextDue = due.format(fmt),
        )
        val next = listOf(item) + refills
        refills = next
        engagement.saveRefills(next)
    }

    fun removeRefill(id: String) {
        val next = refills.filterNot { it.id == id }
        refills = next
        engagement.saveRefills(next)
    }

    fun reorderFrom(order: SavedOrder, onOpenCatalog: () -> Unit): String? {
        val lines = order.orderLines()
        if (lines.isEmpty()) return "Line items were not saved for this order. Add medicines manually."
        val target = branches.find { it.id == order.branchId && order.branchId > 0 }
            ?: branches.find { it.title.equals(order.pharmacyName, ignoreCase = true) }
        if (target == null) return "Pharmacy not in list. Open the store and add items again."
        if (cart.isNotEmpty() && branch?.id != target.id) {
            pendingBranch = target
            return "Switch pharmacy? Confirm on the popup, then tap Reorder again."
        }
        applyBranch(target)
        cart.clear()
        viewModelScope.launch {
            val changes = mutableListOf<String>()
            var lookupFailed = false
            lines.forEach { line ->
                val rows = try {
                    withContext(Dispatchers.IO) {
                        if (target.source == "retail") retailApi.products(target.clientNo, line.name)
                        else api.catalog(target.id, line.name)
                    }
                } catch (_: Exception) {
                    lookupFailed = true
                    return@forEach
                }
                val match = rows.firstOrNull { it.bindingId == line.bindingId }
                    ?: rows.firstOrNull { it.productName.equals(line.name, ignoreCase = true) }
                val max = match?.availableQty?.toInt() ?: 0
                if (match == null || max <= 0) {
                    changes += "${line.name} is out of stock or expired, so it was not added."
                    return@forEach
                }
                val qty = line.qty.coerceIn(1, max)
                if (qty < line.qty) changes += "${line.name}: only $max available, quantity set to $max."
                cart.add(CartLine(match.bindingId, match.productName, qty, match.mrp, max, match.scheduleCategory))
            }
            if (lookupFailed) {
                changes += "Stock could not be checked for some items. Check your internet and add them from the list."
            }
            if (changes.isNotEmpty()) stockLimitNotice = changes.joinToString("\n")
            onOpenCatalog()
        }
        return null
    }

    fun openFavorite(fav: FavoriteMed, onOpenCatalog: () -> Unit) {
        val target = branches.find { it.id == fav.branchId } ?: return
        if (cart.isNotEmpty() && branch?.id != target.id) {
            pendingBranch = target
            return
        }
        applyBranch(target)
        catalogQuery = fav.productName
        onOpenCatalog()
    }

    fun attachStorePrescription(uri: Uri) {
        viewModelScope.launch {
            val encoded = withContext(Dispatchers.IO) { encodePrescription(uri) }
            applyStoreRxEncoded(encoded)
        }
    }

    fun attachStorePrescriptionFromBase64(dataUrl: String) {
        storeRxBase64 = dataUrl
        storeRxLabel = "Prescription attached"
    }

    private fun applyStoreRxEncoded(encoded: String?) {
        if (encoded == null) {
            storeRxLabel = "Could not attach file. Use a photo or smaller PDF."
            storeRxBase64 = null
        } else {
            storeRxBase64 = encoded
            storeRxLabel = "Prescription attached"
        }
    }

    fun clearStorePrescription() {
        storeRxBase64 = null
        storeRxLabel = null
    }

    fun pharmacyWhatsAppPhone(): String {
        branch?.phone?.takeIf { it.filter { ch -> ch.isDigit() }.length >= 10 }?.let { return it }
        agtShop?.pharmacyContact?.takeIf { it.filter { ch -> ch.isDigit() }.length >= 10 }?.let { return it }
        return ""
    }

    private fun checkStockAlerts(branch: Branch, catalog: List<CatalogItem>) {
        if (!notificationsEnabled) return
        val ctx = getApplication<Application>()
        val matched = stockAlerts.filter { alert ->
            alert.branchId == branch.id && (
                (alert.bindingId > 0 && catalog.any { it.bindingId == alert.bindingId && it.availableQty > 0 }) ||
                    (alert.bindingId == 0L && catalog.any {
                        it.productName.contains(alert.productName, ignoreCase = true) && it.availableQty > 0
                    })
                )
        }
        if (matched.isEmpty()) return
        matched.forEach { alert ->
            NotificationHelper.notifyStockBack(ctx, alert.productName, branch.title)
        }
        val next = stockAlerts.filterNot { alert -> matched.any { it.branchId == alert.branchId && it.productName == alert.productName && it.bindingId == alert.bindingId } }
        stockAlerts = next
        engagement.saveStockAlerts(next)
    }

    fun changeQty(item: CatalogItem, delta: Int) {
        val max = item.availableQty.toInt().coerceAtLeast(0)
        val idx = cart.indexOfFirst { it.bindingId == item.bindingId }
        val current = if (idx >= 0) cart[idx].qty else 0
        if (delta > 0 && current + delta > max) {
            stockLimitNotice = stockLimitMessage(item.productName, max)
            if (max <= 0) return
        }
        val next = (current + delta).coerceIn(0, max)
        when {
            next == 0 && idx >= 0 -> cart.removeAt(idx)
            idx >= 0 -> cart[idx] = cart[idx].copy(qty = next)
            next > 0 -> cart.add(
                CartLine(item.bindingId, item.productName, next, item.mrp, max, item.scheduleCategory)
            )
        }
    }

    private fun stockLimitMessage(name: String, max: Int): String =
        if (max <= 0) {
            "$name is out of stock at this pharmacy."
        } else {
            "Only $max ${if (max == 1) "unit is" else "units are"} available for $name. You cannot add more than that."
        }

    fun updateProfile(next: Profile) {
        profile = next
    }

    fun applyUserAccount(user: AgtUserAccount) {
        locationAccount = user
        profile = user.toProfile()
        lookupPhone = user.phone
        prefs.saveProfile(profile)
        orders = prefs.orders()
        selectedOrder = null
        detail = null
        applyNearYouFromAccount(user)
    }

    fun onLoggedOut() {
        locationAccount = null
        orders = emptyList()
        selectedOrder = null
        detail = null
        showAllPharmacies = false
        userLatitude = null
        userLongitude = null
        gpsPlace = null
        locationMessage = null
    }

    private fun applyNearYouFromAccount(user: AgtUserAccount) {
        if (NearYou.hasUserLocation(user.city, user.pincode, user.state)) {
            showAllPharmacies = false
        }
    }

    private suspend fun completeRetailRequest(
        current: Branch,
        p: Profile,
        lines: List<CartLine>,
        onDone: () -> Unit,
    ) {
        val orderNo = "RTL-" + java.time.LocalDate.now().format(
            java.time.format.DateTimeFormatter.ofPattern("yyyyMMdd"),
        ) + "-" + (1000 + (System.currentTimeMillis() % 9000)).toString()
        val total = lines.sumOf { it.rate * it.qty }
        val msg = buildString {
            appendLine("AuraGharTak order · ${current.title}")
            appendLine("Ref: $orderNo")
            appendLine("Name: ${p.name}")
            appendLine("Mobile: ${p.phone}")
            appendLine("Address: ${p.address}")
            if (p.pincode.isNotBlank()) appendLine("PIN: ${p.pincode}")
            appendLine("Payment: ${PaymentMethods.labelFor(paymentMethod)}")
            if (deliverySlot.isNotBlank()) appendLine("Delivery: $deliverySlot")
            if (note.isNotBlank()) appendLine("Note: $note")
            appendLine()
            lines.forEach { appendLine("• ${it.name} × ${it.qty} @ ${it.rate}") }
            appendLine()
            append("Est. total: ₹${String.format(java.util.Locale.US, "%.2f", total)}")
        }
        val ctx = getApplication<Application>()
        withContext(Main) {
            openWhatsAppChat(ctx, current.phone, msg)
        }
        withContext(Dispatchers.IO) {
            runCatching {
                retailApi.placeRetailOrder(
                    current.clientNo,
                    orderNo,
                    p,
                    PaymentMethods.appendToNote(note, paymentMethod),
                    deliverySlot,
                    lines,
                    PaymentMethods.labelFor(paymentMethod),
                )
            }
        }
        val orderLines = lines.map { SavedOrderLine(it.bindingId, it.name, it.qty, it.rate) }
        val saved = SavedOrder(
            orderNo = orderNo,
            pharmacyName = current.title,
            branchId = current.id,
            total = total,
            createdAt = java.time.LocalDateTime.now().toString().replace('T', ' ').take(19),
            status = "sent",
            kind = "retail",
            phone = digits(p.phone).takeLast(10),
            lines = orderLines,
        )
        remember(saved)
        engagement.saveLastKnownStatus(orderNo, saved.status)
        OrderStatusSync.suppressNotificationsFor(5_000)
        lastOrder = saved
        cart.clear()
        note = ""
        clearStorePrescription()
        onDone()
    }

    fun placeOrder(onDone: () -> Unit) {
        if (placing) return
        val current = branch
        val p = profile
        when {
            current == null -> formError = "Select a pharmacy first."
            p.name.isBlank() -> formError = "Enter your name."
            digits(p.phone).length < 10 -> formError = "Enter a 10-digit mobile number."
            p.address.isBlank() -> formError = "Enter the delivery address."
            cart.isEmpty() -> formError = "Your cart is empty."
            else -> formError = null
        }
        if (formError != null || current == null) return
        val lines = cart.toList()
        viewModelScope.launch {
            placing = true
            try {
                prefs.saveProfile(p)
                lookupPhone = p.phone
                if (current.source == "retail") {
                    completeRetailRequest(current, p, lines, onDone)
                    return@launch
                }
                val rx = storeRxBase64
                val slot = deliverySlot
                val payLabel = PaymentMethods.labelFor(paymentMethod)
                val orderNote = PaymentMethods.appendToNote(note, paymentMethod)
                val result = withContext(Dispatchers.IO) {
                    api.placeOrder(current.id, p, orderNote, lines, slot, rx, payLabel, deliveryDropPoint(p))
                }
                val orderLines = lines.map { SavedOrderLine(it.bindingId, it.name, it.qty, it.rate) }
                val saved = SavedOrder(
                    orderNo = result.orderNo,
                    trackingToken = result.trackingToken,
                    pharmacyName = current.title,
                    branchId = current.id,
                    total = result.total,
                    createdAt = java.time.LocalDateTime.now().toString().replace('T', ' ').take(19),
                    status = "placed",
                    kind = "store",
                    phone = digits(p.phone).takeLast(10),
                    lines = orderLines,
                )
                remember(saved)
                engagement.saveLastKnownStatus(result.orderNo, "placed")
                OrderStatusSync.suppressNotificationsFor(5_000)
                lastOrder = saved
                cart.clear()
                note = ""
                clearStorePrescription()
                onDone()
            } catch (e: ApiException) {
                formError = e.message
            } finally {
                placing = false
            }
        }
    }

    fun refreshOrders() {
        val phone = digits(lookupPhone).takeLast(10)
        viewModelScope.launch {
            ordersLoading = true
            ordersError = null
            val local = prefs.orders()
            if (phone.length < 10) {
                orders = local
                ordersLoading = false
                return@launch
            }
            try {
                val remote = withContext(Dispatchers.IO) { api.track(phone = phone) }
                val summaries = when (remote) {
                    is TrackResult.Many -> remote.orders
                    is TrackResult.One -> listOf(
                        OrderSummary(
                            remote.detail.orderNo,
                            remote.detail.customerName,
                            remote.detail.status,
                            remote.detail.total,
                            remote.detail.createdAt,
                            orderKind = "store",
                        ),
                    )
                }
                val deduped = dedupeRemoteTrackSummaries(summaries)
                val retailRows = runCatching {
                    withContext(Dispatchers.IO) { retailApi.listOrdersByPhone(phone) }
                }.getOrDefault(emptyList())
                val ctx = getApplication<Application>()
                val merged = dedupeMergedOrders(merge(local, deduped, phone, retailRows))
                val beforeKnown = merged.associate { it.orderNo to engagement.lastKnownStatus(it.orderNo) }
                merged.forEach { order ->
                    val prevLocal = local.find { it.orderNo == order.orderNo }?.status.orEmpty()
                    val prevEng = beforeKnown[order.orderNo].orEmpty()
                    OrderStatusSync.onStatusLearned(
                        ctx,
                        order.orderNo,
                        order.status,
                        prevEng.takeIf { it.isNotBlank() },
                        savedStatusHint = prevLocal.ifBlank { prevEng },
                        bypassSuppress = true,
                    )
                }
                orders = merged
                prefs.saveOrders(orders)
                runCatching {
                    withContext(Dispatchers.IO) {
                        OrderStatusSync.pollAllSavedOrders(ctx)
                    }
                    orders = prefs.orders()
                }
            } catch (e: ApiException) {
                orders = local
                if (local.isEmpty()) ordersError = e.message
            } finally {
                ordersLoading = false
            }
        }
    }

    fun openOrder(order: SavedOrder) {
        selectedOrder = order
        detail = null
        detailError = null
        if (order.kind == "agt") {
            viewModelScope.launch {
                detailLoading = true
                detailError = null
                try {
                    val remote = withContext(Dispatchers.IO) { api.track(orderNo = order.orderNo) }
                    if (remote is TrackResult.One) {
                        detail = remote.detail.copy(
                            pharmacyName = order.pharmacyName,
                            lines = remote.detail.lines.ifEmpty { order.orderLines() },
                        )
                        patchStatus(order.orderNo, remote.detail.status)
                    } else {
                        detail = OrderDetail(
                            orderNo = order.orderNo,
                            customerName = profile.name,
                            phone = order.phone.ifBlank { profile.phone },
                            status = order.status,
                            total = order.total,
                            createdAt = order.createdAt,
                            cancellable = false,
                            events = listOf(
                                OrderEvent(
                                    status = order.status,
                                    note = "Placed via pharmacy link",
                                    createdAt = order.createdAt,
                                ),
                            ),
                            pharmacyName = order.pharmacyName,
                            lines = order.orderLines(),
                        )
                    }
                } catch (e: ApiException) {
                    detailError = e.message
                } finally {
                    detailLoading = false
                }
            }
            return
        }
        if (order.kind == "retail") {
            detail = OrderDetail(
                orderNo = order.orderNo,
                customerName = profile.name,
                phone = order.phone.ifBlank { profile.phone },
                status = order.status,
                total = order.total,
                createdAt = order.createdAt,
                cancellable = false,
                events = listOf(
                    OrderEvent(
                        status = order.status,
                        note = "Placed on AuraGharTak (retail pharmacy)",
                        createdAt = order.createdAt,
                    ),
                ),
                pharmacyName = order.pharmacyName,
                lines = order.orderLines(),
            )
            return
        }
        if (order.kind != "store") return
        viewModelScope.launch {
            detailLoading = true
            try {
                val remote = withContext(Dispatchers.IO) {
                    if (order.trackingToken.isNotBlank()) api.track(token = order.trackingToken)
                    else api.track(orderNo = order.orderNo)
                }
                if (remote is TrackResult.One) {
                    detail = remote.detail.copy(
                        pharmacyName = order.pharmacyName,
                        lines = remote.detail.lines.ifEmpty { order.orderLines() },
                    )
                    patchStatus(order.orderNo, remote.detail.status)
                }
            } catch (e: ApiException) {
                detailError = e.message
            } finally {
                detailLoading = false
            }
        }
    }

    fun cancelSelected() {
        val order = selectedOrder ?: return
        val current = detail ?: return
        if (!current.cancellable || cancelling) return
        viewModelScope.launch {
            cancelling = true
            detailError = null
            try {
                withContext(Dispatchers.IO) {
                    if (order.trackingToken.isNotBlank()) api.cancel(token = order.trackingToken)
                    else api.cancel(orderNo = current.orderNo, phone = digits(lookupPhone).takeLast(10))
                }
                patchStatus(order.orderNo, "cancelled")
                openOrder(order.copy(status = "cancelled", trackingToken = order.trackingToken))
            } catch (e: ApiException) {
                detailError = e.message
            } finally {
                cancelling = false
            }
        }
    }

    fun openAgt(raw: String) {
        val token = extractToken(raw)
        if (token.isBlank()) {
            agtError = "Paste the link your pharmacy sent you."
            return
        }
        agtTokenInput = token
        agtDone = null
        agtCart.clear()
        agtQuery = ""
        loadAgt()
    }

    fun loadAgt() {
        val token = extractToken(agtTokenInput)
        if (token.isBlank()) return
        val q = agtQuery
        val gen = ++agtGen
        viewModelScope.launch {
            agtLoading = true
            agtError = null
            try {
                val shop = withContext(Dispatchers.IO) { api.agtShop(token, q) }
                if (gen == agtGen) agtShop = shop
            } catch (e: ApiException) {
                if (gen == agtGen) {
                    agtError = e.message
                    if (q.isBlank()) agtShop = null
                }
            } finally {
                if (gen == agtGen) agtLoading = false
            }
        }
    }

    fun resetAgt() {
        agtShop = null
        agtCart.clear()
        agtDone = null
        agtError = null
        agtNote = ""
        agtRxBase64 = null
        agtRxLabel = null
        agtQuery = ""
    }

    fun changeAgtQty(item: AgtProduct, delta: Int) {
        val max = item.availableQty.toInt().coerceAtLeast(0)
        val idx = agtCart.indexOfFirst { it.name == item.name && it.batchNo == item.batchNo }
        val current = if (idx >= 0) agtCart[idx].qty else 0
        if (delta > 0 && current + delta > max) {
            stockLimitNotice = stockLimitMessage(item.name, max)
            if (max <= 0) return
        }
        val next = (current + delta).coerceIn(0, max)
        when {
            next == 0 && idx >= 0 -> agtCart.removeAt(idx)
            idx >= 0 -> agtCart[idx] = agtCart[idx].copy(qty = next)
            next > 0 -> agtCart.add(AgtCartLine(item.name, item.batchNo, next, item.unitMrp, max))
        }
    }

    fun attachPrescription(uri: Uri) {
        viewModelScope.launch {
            val encoded = withContext(Dispatchers.IO) { encodePrescription(uri) }
            applyAgtRxEncoded(encoded)
        }
    }

    fun attachPrescriptionFromBase64(dataUrl: String) {
        agtRxBase64 = dataUrl
        agtRxLabel = "Prescription attached"
    }

    private fun applyAgtRxEncoded(encoded: String?) {
            if (encoded == null) {
                agtRxLabel = "That file could not be attached. Use a photo or a smaller PDF."
                agtRxBase64 = null
            } else {
                agtRxBase64 = encoded
                agtRxLabel = "Prescription attached"
        }
    }

    fun clearPrescription() {
        agtRxBase64 = null
        agtRxLabel = null
    }

    fun placeAgt(onDone: () -> Unit) {
        val shop = agtShop
        when {
            shop == null -> agtError = "Open your pharmacy link first."
            agtCart.isEmpty() -> agtError = "Add at least one medicine."
            else -> agtError = null
        }
        if (agtError != null || shop == null) return
        val lines = agtCart.toList()
        val rx = agtRxBase64
        val noteText = PaymentMethods.appendToNote(agtNote, paymentMethod)
        val payLabel = PaymentMethods.labelFor(paymentMethod)
        viewModelScope.launch {
            agtPlacing = true
            try {
                val result = withContext(Dispatchers.IO) {
                    api.placeAgt(
                        shop.token,
                        lines,
                        noteText,
                        rx,
                        payLabel,
                        deliveryDropPoint(Profile(address = shop.patientAddress)),
                    )
                }
                remember(
                    SavedOrder(
                        orderNo = result.orderNo,
                        pharmacyName = shop.pharmacyName,
                        total = result.total,
                        createdAt = java.time.LocalDateTime.now().toString().replace('T', ' ').take(19),
                        status = "Placed",
                        kind = "agt",
                        phone = digits(shop.patientContact).takeLast(10),
                    )
                )
                engagement.saveLastKnownStatus(result.orderNo, "Placed")
                agtDone = result
                agtCart.clear()
                agtNote = ""
                clearPrescription()
                onDone()
            } catch (e: ApiException) {
                agtError = e.message
            } finally {
                agtPlacing = false
            }
        }
    }

    /** Customer map pin for live delivery: geocoded delivery address, else PIN, else current GPS. Call off main thread. */
    private suspend fun deliveryDropPoint(p: Profile): Pair<Double, Double>? {
        val address = p.address.trim()
        val pin = NearYou.pinDigits(p.pincode)
        return runCatching {
            (if (address.isNotBlank()) locationHelper.geocodeAddress(listOf(address, pin).filter { it.isNotBlank() }.joinToString(", ") + ", India") else null)
                ?: (if (pin.length == 6) locationHelper.geocodePincode(pin) else null)
                ?: locationHelper.currentLatLng()
                ?: userLatitude?.let { lat -> userLongitude?.let { lng -> lat to lng } }
        }.getOrNull()
    }

    private fun remember(order: SavedOrder) {
        val next = listOf(order) + prefs.orders().filter { it.orderNo != order.orderNo }
        prefs.saveOrders(next)
        orders = prefs.orders()
    }

    private fun patchStatus(orderNo: String, status: String) {
        val next = prefs.orders().map { if (it.orderNo == orderNo) it.copy(status = status) else it }
        prefs.saveOrders(next)
        orders = next
        if (selectedOrder?.orderNo == orderNo) selectedOrder = selectedOrder?.copy(status = status)
        engagement.saveLastKnownStatus(orderNo, status)
    }

    private var orderPollJob: kotlinx.coroutines.Job? = null

    fun startOrderStatusPolling() {
        if (orderPollJob?.isActive == true) return
        orderPollJob = viewModelScope.launch {
            while (isActive) {
                if (notificationsEnabled) {
                    runCatching {
                        OrderStatusSync.pollAllSavedOrders(getApplication())
                        orders = prefs.orders()
                    }
                }
                delay(30_000)
            }
        }
    }

    private fun dedupeMergedOrders(rows: List<SavedOrder>): List<SavedOrder> {
        val agts = rows.filter { it.kind == "agt" }
        val stores = rows.filter { it.kind == "store" }.map { store ->
            val mirror = agts.firstOrNull { isLikelyStoreMirrorSaved(store, it) }
            if (mirror != null) {
                store.copy(status = OrderStatusSync.pickBetterStatus(store.status, mirror.status))
            } else {
                store
            }
        }
        val others = rows.filter { it.kind != "store" && it.kind != "agt" }
        if (stores.isEmpty()) return rows
        val keptAgt = agts.filter { agt -> stores.none { isLikelyStoreMirrorSaved(it, agt) } }
        return stores + keptAgt + others
    }

    private fun isLikelyStoreMirrorSaved(store: SavedOrder, agt: SavedOrder): Boolean {
        if (kotlin.math.abs(store.total - agt.total) > 1.0) return false
        val t1 = parseOrderTime(store.createdAt)
        val t2 = parseOrderTime(agt.createdAt)
        if (t1 <= 0L || t2 <= 0L) return false
        return kotlin.math.abs(t1 - t2) <= 20 * 60 * 1000L
    }

    private fun dedupeRemoteTrackSummaries(rows: List<OrderSummary>): List<OrderSummary> {
        val agts = rows.filter { it.orderKind.equals("agt", ignoreCase = true) }
        val stores = rows.filter { !it.orderKind.equals("agt", ignoreCase = true) }.map { store ->
            val mirror = agts.firstOrNull { isLikelyStoreMirror(store, it) }
            if (mirror != null) {
                store.copy(status = OrderStatusSync.pickBetterStatus(store.status, mirror.status))
            } else {
                store
            }
        }
        if (stores.isEmpty()) return rows
        return stores + agts.filter { agt -> stores.none { isLikelyStoreMirror(it, agt) } }
    }

    private fun isLikelyStoreMirror(store: OrderSummary, agt: OrderSummary): Boolean {
        if (kotlin.math.abs(store.total - agt.total) > 1.0) return false
        val t1 = parseOrderTime(store.createdAt)
        val t2 = parseOrderTime(agt.createdAt)
        if (t1 <= 0L || t2 <= 0L) return false
        return kotlin.math.abs(t1 - t2) <= 20 * 60 * 1000L
    }

    private fun parseOrderTime(raw: String): Long {
        val s = raw.trim()
        if (s.isBlank()) return 0L
        return runCatching {
            java.time.LocalDateTime.parse(s.replace(' ', 'T').take(19))
                .atZone(java.time.ZoneId.systemDefault())
                .toInstant()
                .toEpochMilli()
        }.getOrElse {
            runCatching {
                java.time.Instant.parse(s).toEpochMilli()
            }.getOrDefault(0L)
        }
    }

    private fun merge(
        local: List<SavedOrder>,
        remote: List<OrderSummary>,
        phone: String,
        retailRemote: List<RetailOrderSummary> = emptyList(),
    ): List<SavedOrder> {
        val byNo = local.associateBy { it.orderNo }
        val remoteNos = remote.map { it.orderNo }.toSet()
        val retailNos = retailRemote.map { it.orderNo }.toSet()
        val fromServer = remote.map { row ->
            val prev = byNo[row.orderNo]
            val kind = when {
                row.orderKind.equals("agt", ignoreCase = true) -> "agt"
                prev?.kind?.isNotBlank() == true -> prev.kind
                else -> "store"
            }
            SavedOrder(
                orderNo = row.orderNo,
                trackingToken = prev?.trackingToken.orEmpty(),
                pharmacyName = prev?.pharmacyName?.ifBlank { "Paperless Pharmacy" } ?: "Paperless Pharmacy",
                total = row.total,
                createdAt = row.createdAt.ifBlank { prev?.createdAt.orEmpty() },
                status = row.status.ifBlank { prev?.status ?: "placed" },
                kind = kind,
                phone = phone,
                lines = prev?.orderLines(),
            )
        }
        val fromRetail = retailRemote.map { row ->
            val prev = byNo[row.orderNo]
            SavedOrder(
                orderNo = row.orderNo,
                pharmacyName = row.pharmacyName.ifBlank { prev?.pharmacyName.orEmpty() },
                total = if (row.total > 0) row.total else prev?.total ?: 0.0,
                createdAt = row.createdAt.ifBlank { prev?.createdAt.orEmpty() },
                status = row.status.ifBlank { prev?.status ?: "Placed" },
                kind = "retail",
                phone = phone,
                branchId = prev?.branchId ?: 0L,
                lines = prev?.orderLines(),
            )
        }
        val kept = local.filter { saved ->
            when {
                saved.orderNo in remoteNos || saved.orderNo in retailNos -> false
                saved.kind == "agt" && fromServer.any { store ->
                    store.kind == "store" && isLikelyStoreMirrorSaved(store, saved)
                } -> false
                saved.phone.isBlank() || saved.phone.takeLast(10) == phone -> true
                else -> false
            }
        }
        return (fromServer + fromRetail + kept).sortedByDescending { it.createdAt }
    }

    private fun encodePrescription(uri: Uri): String? {
        val resolver = getApplication<Application>().contentResolver
        val mime = resolver.getType(uri).orEmpty().lowercase()
        val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
        if (mime == "application/pdf") {
            if (bytes.size > 6 * 1024 * 1024) return null
            return "data:application/pdf;base64," + Base64.encodeToString(bytes, Base64.NO_WRAP)
        }
        val bitmap = BitmapFactory.decodeByteArray(bytes, 0, bytes.size) ?: return null
        val maxSide = 1600
        val scale = minOf(1f, maxSide / maxOf(bitmap.width, bitmap.height).toFloat())
        val scaled = if (scale < 1f) {
            Bitmap.createScaledBitmap(bitmap, (bitmap.width * scale).toInt(), (bitmap.height * scale).toInt(), true)
        } else {
            bitmap
        }
        val out = ByteArrayOutputStream()
        scaled.compress(Bitmap.CompressFormat.JPEG, 80, out)
        if (scaled !== bitmap) scaled.recycle()
        if (out.size() > 6 * 1024 * 1024) return null
        return "data:image/jpeg;base64," + Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
    }
}

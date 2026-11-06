package in.paperlesspharmacy.auraghatak.shop

import android.app.Application
import android.net.Uri
import android.util.Base64
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import in.paperlesspharmacy.auraghatak.data.api.ApiClient
import in.paperlesspharmacy.auraghatak.data.api.CartItemDto
import in.paperlesspharmacy.auraghatak.data.api.PlaceOrderRequest
import in.paperlesspharmacy.auraghatak.data.api.PharmacyInfo
import in.paperlesspharmacy.auraghatak.data.api.ProductRow
import in.paperlesspharmacy.auraghatak.data.api.PublicBranch
import in.paperlesspharmacy.auraghatak.data.local.AppPreferences
import in.paperlesspharmacy.auraghatak.data.local.LocalOrder
import in.paperlesspharmacy.auraghatak.data.local.SavedPharmacy
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException
import java.io.IOException

data class ShopUiState(
    val loading: Boolean = false,
    val error: String? = null,
    val token: String = "",
    val pharmacy: PharmacyInfo? = null,
    val patientName: String = "",
    val patientContact: String = "",
    val searchQuery: String = "",
    val products: List<ProductRow> = emptyList(),
    val cart: List<CartLine> = emptyList(),
    val patientNote: String = "",
    val prescriptionBase64: String? = null,
    val placing: Boolean = false,
    val orderSuccess: Pair<String, Double>? = null,
    val branches: List<PublicBranch> = emptyList(),
    val branchesLoading: Boolean = false,
)

class ShopViewModel(app: Application) : AndroidViewModel(app) {
    private val prefs = AppPreferences(app)
    private val _ui = MutableStateFlow(ShopUiState())
    val ui: StateFlow<ShopUiState> = _ui.asStateFlow()

    val savedPharmacies = prefs.pharmacies.stateIn(
        viewModelScope,
        SharingStarted.WhileSubscribed(5_000),
        emptyList(),
    )
    val localOrders = prefs.orders.stateIn(
        viewModelScope,
        SharingStarted.WhileSubscribed(5_000),
        emptyList(),
    )

    fun connectToken(token: String) {
        if (token.isBlank()) return
        _ui.update { it.copy(token = token, error = null, orderSuccess = null) }
        loadShop()
    }

    fun loadShop() {
        val token = _ui.value.token
        if (token.isBlank()) return
        viewModelScope.launch {
            _ui.update { it.copy(loading = true, error = null) }
            try {
                val res = ApiClient.agt.shop(token, _ui.value.searchQuery.trim())
                if (res.success != true || res.data == null) {
                    _ui.update { it.copy(loading = false, error = res.message ?: "Could not load shop.") }
                    return@launch
                }
                val d = res.data
                val pharmacy = d.pharmacy
                _ui.update {
                    it.copy(
                        loading = false,
                        pharmacy = pharmacy,
                        patientName = d.patient?.name.orEmpty(),
                        patientContact = d.patient?.contact.orEmpty(),
                        products = d.products.orEmpty(),
                    )
                }
                prefs.upsertPharmacy(
                    SavedPharmacy(
                        token = token,
                        name = pharmacy?.name.orEmpty().ifBlank { "Pharmacy" },
                        address = pharmacy?.address.orEmpty(),
                        contact = pharmacy?.contact.orEmpty(),
                        patientName = d.patient?.name.orEmpty(),
                    ),
                )
            } catch (e: HttpException) {
                _ui.update { it.copy(loading = false, error = parseHttp(e)) }
            } catch (_: IOException) {
                _ui.update { it.copy(loading = false, error = "Network error. Check connection and API URL.") }
            } catch (e: Exception) {
                _ui.update { it.copy(loading = false, error = e.message ?: "Something went wrong.") }
            }
        }
    }

    fun setSearchQuery(q: String) {
        _ui.update { it.copy(searchQuery = q) }
    }

    fun search() = loadShop()

    fun changeQty(product: ProductRow, delta: Int) {
        val name = product.productName.orEmpty()
        val batch = product.batchNo.orEmpty()
        val max = product.availableQty?.toInt()?.coerceAtLeast(0) ?: 0
        val unit = product.unitMrp ?: product.mrp ?: 0.0
        _ui.update { state ->
            val cart = state.cart.toMutableList()
            val idx = cart.indexOfFirst { it.productName == name && it.batchNo == batch }
            var qty = (if (idx >= 0) cart[idx].qty else 0) + delta
            qty = qty.coerceIn(0, max)
            if (qty == 0) {
                if (idx >= 0) cart.removeAt(idx)
            } else if (idx >= 0) {
                cart[idx] = cart[idx].copy(qty = qty)
            } else {
                cart.add(CartLine(name, batch, unit, max, qty))
            }
            state.copy(cart = cart)
        }
    }

    fun qtyInCart(product: ProductRow): Int {
        val name = product.productName.orEmpty()
        val batch = product.batchNo.orEmpty()
        return _ui.value.cart.find { it.productName == name && it.batchNo == batch }?.qty ?: 0
    }

    fun setPatientNote(note: String) {
        _ui.update { it.copy(patientNote = note) }
    }

    fun setPrescriptionFromUri(uri: Uri) {
        viewModelScope.launch {
            runCatching {
                val cr = getApplication<Application>().contentResolver
                val mime = cr.getType(uri) ?: "image/jpeg"
                cr.openInputStream(uri)?.use { stream ->
                    val bytes = stream.readBytes()
                    if (bytes.size > 8 * 1024 * 1024) throw IllegalArgumentException("File too large (max 8 MB).")
                    val b64 = Base64.encodeToString(bytes, Base64.NO_WRAP)
                    "data:$mime;base64,$b64"
                }
            }.onSuccess { encoded ->
                _ui.update { it.copy(prescriptionBase64 = encoded) }
            }.onFailure { e ->
                _ui.update { it.copy(error = e.message ?: "Could not read prescription file.") }
            }
        }
    }

    fun clearPrescription() {
        _ui.update { it.copy(prescriptionBase64 = null) }
    }

    fun cartTotal(): Double = _ui.value.cart.sumOf { it.lineTotal }

    fun placeOrder() {
        val state = _ui.value
        if (state.cart.isEmpty()) {
            _ui.update { it.copy(error = "Add at least one product.") }
            return
        }
        viewModelScope.launch {
            _ui.update { it.copy(placing = true, error = null) }
            try {
                val body = PlaceOrderRequest(
                    token = state.token,
                    items = state.cart.map {
                        CartItemDto(it.productName, it.batchNo, it.qty, it.unitMrp)
                    },
                    patientNote = state.patientNote.takeIf { it.isNotBlank() },
                    prescriptionBase64 = state.prescriptionBase64,
                )
                val res = ApiClient.agt.placeOrder(body)
                if (res.success != true || res.data?.orderNo.isNullOrBlank()) {
                    _ui.update { it.copy(placing = false, error = res.message ?: "Could not place order.") }
                    return@launch
                }
                val orderNo = res.data.orderNo!!
                val total = res.data.estimatedTotal ?: cartTotal()
                prefs.addOrder(
                    LocalOrder(
                        orderNo = orderNo,
                        pharmacyName = state.pharmacy?.name.orEmpty(),
                        estimatedTotal = total,
                    ),
                )
                _ui.update {
                    it.copy(
                        placing = false,
                        cart = emptyList(),
                        patientNote = "",
                        prescriptionBase64 = null,
                        orderSuccess = orderNo to total,
                    )
                }
            } catch (e: HttpException) {
                _ui.update { it.copy(placing = false, error = parseHttp(e)) }
            } catch (_: IOException) {
                _ui.update { it.copy(placing = false, error = "Network error while placing order.") }
            } catch (e: Exception) {
                _ui.update { it.copy(placing = false, error = e.message ?: "Could not place order.") }
            }
        }
    }

    fun clearOrderSuccess() {
        _ui.update { it.copy(orderSuccess = null) }
    }

    fun clearError() {
        _ui.update { it.copy(error = null) }
    }

    fun loadBranches() {
        viewModelScope.launch {
            _ui.update { it.copy(branchesLoading = true) }
            try {
                val res = ApiClient.agt.branches()
                _ui.update {
                    it.copy(
                        branchesLoading = false,
                        branches = res.data.orEmpty(),
                    )
                }
            } catch (_: Exception) {
                _ui.update { it.copy(branchesLoading = false) }
            }
        }
    }

    private fun parseHttp(e: HttpException): String {
        val body = e.response()?.errorBody()?.string()
        if (!body.isNullOrBlank() && body.contains("message")) {
            return Regex("\"message\"\\s*:\\s*\"([^\"]+)\"").find(body)?.groupValues?.get(1) ?: e.message()
        }
        return e.message()
    }
}

package `in`.paperlesspharmacy.advanced.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import `in`.paperlesspharmacy.advanced.AdvancedPharmacyApp
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.master.MasterConfigDto
import `in`.paperlesspharmacy.advanced.data.master.MasterOptionsService
import `in`.paperlesspharmacy.advanced.data.transaction.AgtRepository
import `in`.paperlesspharmacy.advanced.data.transaction.CareRepository
import `in`.paperlesspharmacy.advanced.data.transaction.HoRepository
import `in`.paperlesspharmacy.advanced.data.transaction.PaymentsRepository
import `in`.paperlesspharmacy.advanced.data.transaction.ProfileRepository
import `in`.paperlesspharmacy.advanced.data.transaction.ReturnsRepository
import `in`.paperlesspharmacy.advanced.data.transaction.ImportRepository
import `in`.paperlesspharmacy.advanced.data.transaction.PurchaseOrderRepository
import `in`.paperlesspharmacy.advanced.data.transaction.PurchaseRepository
import `in`.paperlesspharmacy.advanced.data.transaction.SalesRepository
import `in`.paperlesspharmacy.advanced.data.transaction.StockRepository
import `in`.paperlesspharmacy.advanced.data.transaction.SupportRepository
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class UiMessage(val text: String, val isError: Boolean = true)

data class AgtDoSale(
    val orderId: Int,
    val orderNo: String,
    val patientNo: String,
    val patientName: String,
    val patientContact: String,
    val patientAddress: String,
)

class AppViewModel(app: Application) : AndroidViewModel(app) {
    private val pharmacyApp = app as AdvancedPharmacyApp
    private val session = pharmacyApp.sessionStore
    val api = ApiClient(session)
    val crud = CrudRepository(api)
    val rights = UserRightsRepository(api, session)
    val masterConfigs: Map<String, MasterConfigDto> = pharmacyApp.masterConfigs

    fun masterOptions(): MasterOptionsService = MasterOptionsService(pharmacyApp, api, crud)

    fun purchases(): PurchaseRepository = PurchaseRepository(api, crud)

    fun sales(): SalesRepository = SalesRepository(api, crud)

    fun purchaseOrders(): PurchaseOrderRepository = PurchaseOrderRepository(api, crud)

    fun imports(): ImportRepository = ImportRepository(api)

    fun stock(): StockRepository = StockRepository(api, crud)

    fun agt(): AgtRepository = AgtRepository(api)

    fun care(): CareRepository = CareRepository(api, crud)

    fun returns(): ReturnsRepository = ReturnsRepository(api)

    fun ho(): HoRepository = HoRepository(api)

    fun payments(): PaymentsRepository = PaymentsRepository(api)

    fun profile(): ProfileRepository = ProfileRepository(api)

    fun support(): SupportRepository = SupportRepository(api)

    /** Edit right for a module (admins bypass). */
    fun canEditModule(code: String): Boolean = rights.hasFullAccess() || rights.canEdit(code)

    /** Open Support ticket from notification popup: Pair(ticketId, focusReply). */
    private val _supportOpenTicket = MutableStateFlow(0 to false)
    val supportOpenTicket = _supportOpenTicket.asStateFlow()

    fun openSupportTicket(id: Int, focusReply: Boolean = false) {
        _supportOpenTicket.value = id to focusReply
    }

    fun clearSupportOpenTicket() {
        _supportOpenTicket.value = 0 to false
    }

    /** One-shot AuraGharTak "Do Sale" handoff to the new sale screen (web sessionStorage `agt_do_sale`). */
    val agtDoSale = MutableStateFlow<AgtDoSale?>(null)

    /** Order to open when the AuraGharTak screen shows (web `?openOrder=`). */
    val agtOpenOrder = MutableStateFlow(0)

    /**
     * Import Bill draft retained across rotation / Activity recreation.
     * AI extract also runs on [viewModelScope] so it is not cancelled when the UI is rebuilt.
     */
    val importBillSession = ImportBillSession()

    fun canUseAgt(): Boolean =
        rights.hasFullAccess() || rights.canView("AURA_GHAR_TAK") || rights.canEdit("AURA_GHAR_TAK")

    fun canEditAgt(): Boolean = rights.hasFullAccess() || rights.canEdit("AURA_GHAR_TAK")

    private val _user = MutableStateFlow(session.user)
    val user = _user.asStateFlow()

    private val _booting = MutableStateFlow(session.accessToken != null)
    val booting = _booting.asStateFlow()

    private val _message = MutableStateFlow<UiMessage?>(null)
    val message = _message.asStateFlow()

    private val _businessType = MutableStateFlow(session.businessType())
    val businessType = _businessType.asStateFlow()

    private val _needsPayment = MutableStateFlow(session.needsPayment)
    val needsPayment = _needsPayment.asStateFlow()

    private val _trialDaysLeft = MutableStateFlow(session.trialDaysLeft.takeIf { it >= 0 })
    val trialDaysLeft = _trialDaysLeft.asStateFlow()

    init {
        if (session.accessToken != null) {
            rights.hydrateFromCache()
            refreshSession()
        } else {
            _booting.value = false
        }
    }

    fun markSubscriptionPaid() {
        session.saveSubscription(false, null)
        _needsPayment.value = false
        _trialDaysLeft.value = null
    }

    fun openPaywall() {
        _needsPayment.value = true
        session.needsPayment = true
    }

    /** After payment: clear paywall and load ERP session data. */
    fun afterSubscriptionActivated(onDone: (() -> Unit)? = null) {
        viewModelScope.launch {
            markSubscriptionPaid()
            runCatching {
                withContext(Dispatchers.IO) {
                    rights.reset()
                    rights.loadMyRights()
                    loadProfileMeta()
                }
            }
            _user.value = session.user
            onDone?.invoke()
        }
    }

    fun clearMessage() {
        _message.value = null
    }

    fun login(email: String, password: String, onDone: (Boolean) -> Unit) {
        viewModelScope.launch {
            val ok = runCatching {
                withContext(Dispatchers.IO) {
                    val tokens = api.login(email.trim(), password)
                    session.storeSession(tokens)
                    _needsPayment.value = tokens.needsPayment
                    _trialDaysLeft.value = tokens.trialDaysLeft
                    if (!tokens.needsPayment) {
                        rights.reset()
                        rights.loadMyRights()
                        loadProfileMeta()
                    }
                    tokens
                }
            }.fold(
                onSuccess = { tokens ->
                    _user.value = session.user
                    _needsPayment.value = tokens.needsPayment
                    _trialDaysLeft.value = tokens.trialDaysLeft
                    _booting.value = false
                    true
                },
                onFailure = {
                    _message.value = UiMessage(it.message ?: "Login failed.")
                    false
                },
            )
            onDone(ok)
        }
    }

    fun logout(onDone: () -> Unit) {
        viewModelScope.launch {
            withContext(Dispatchers.IO) {
                runCatching { api.logout() }
                session.clear()
                rights.reset()
            }
            _user.value = null
            _needsPayment.value = false
            _trialDaysLeft.value = null
            onDone()
        }
    }

    private fun refreshSession() {
        viewModelScope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    val sub = runCatching { api.subscriptionStatus() }.getOrNull()
                    if (sub != null) {
                        val needs = sub.bool("needs_payment")
                        val days = sub.optInt("days_left")
                        session.saveSubscription(needs, days)
                        _needsPayment.value = needs
                        _trialDaysLeft.value = days
                    }
                    if (!_needsPayment.value) {
                        rights.loadMyRights()
                        loadProfileMeta()
                    }
                }
            }.onFailure {
                if (it is ApiException && (it.status == 401 || it.message?.contains("401") == true)) {
                    session.clear()
                    _user.value = null
                }
            }
            _user.value = session.user
            _needsPayment.value = session.needsPayment
            _booting.value = false
        }
    }

    private fun loadProfileMeta() {
        val profile = api.get("meta/profile").obj("data")
        val biz = profile.str("business_type").ifBlank { "retail" }
        session.saveBusinessType(biz)
        _businessType.value = biz
    }

    fun visibleNav(): List<NavModule> {
        val role = _user.value?.role ?: return emptyList()
        val biz = _businessType.value
        return MAIN_NAV.filter { nav ->
            if (!nav.roles.contains(role)) return@filter false
            if (nav.hideForRetail && biz == "retail") return@filter false
            val code = nav.moduleCode
            if (code == null) return@filter true
            if (nav.openLog) return@filter true
            if (rights.hasFullAccess()) return@filter true
            if (!rights.isReady()) return@filter false
            // Each module uses its own rights (Purchase Edit does not unlock Import / Opening Stock).
            rights.canView(code) || rights.canEdit(code)
        }
    }

    fun canEdit(moduleCode: String): Boolean = rights.canEdit(moduleCode)
    fun canDelete(moduleCode: String): Boolean = rights.canDelete(moduleCode)
    fun canView(moduleCode: String): Boolean = rights.canView(moduleCode)
    fun hasFullAccess(): Boolean = rights.hasFullAccess()
}

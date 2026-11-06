package `in`.paperlesspharmacy.advanced.ui

import androidx.compose.runtime.Immutable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableDoubleStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.google.gson.JsonObject
import java.time.LocalDate
import java.util.concurrent.atomic.AtomicLong
import kotlinx.coroutines.Job

private val importLineKeys = AtomicLong(1)

@Immutable
data class ImportLine(
    val key: Long = importLineKeys.getAndIncrement(),
    /** Name as it came from the CSV / bill. */
    val product: String = "",
    val matchedName: String = "",
    val masterProductId: Int? = null,
    val matches: List<JsonObject> = emptyList(),
    val hsn: String = "",
    val batch: String = "",
    /** yyyy-MM */
    val expiry: String = "",
    val pack: String = "",
    val packRaw: String = "",
    val upp: Int = 1,
    val mfg: String = "",
    val qty: Int = 0,
    val free: Int = 0,
    val rate: Double = 0.0,
    val mrp: Double = 0.0,
    val ptr: Double = 0.0,
    val pts: Double = 0.0,
    val discPct: Double = 0.0,
    val discAmt: Double = 0.0,
    val gst: Double = 0.0,
    /** Imported gross / discount amounts — kept while they still reconcile with qty × rate. */
    val srcGross: Double = 0.0,
    val srcDisc: Double = 0.0,
    /** Upload PO: GST = CGST + SGST halves, each rounded (Retail calculateTaxable). */
    val splitGst: Boolean = false,
    val gross: Double = 0.0,
    val cdAmt: Double = 0.0,
    val taxable: Double = 0.0,
    val gstAmt: Double = 0.0,
    val net: Double = 0.0,
)

/**
 * Import Bill draft + in-flight AI extract flags held on [AppViewModel] so rotation /
 * Activity recreation does not wipe lines or cancel the user's place in the flow.
 * The actual network job runs on [AppViewModel.viewModelScope].
 */
class ImportBillSession {
    var panel by mutableStateOf("Upload")
    var verify by mutableStateOf(false)
    var busy by mutableStateOf(false)
    var busyLabel by mutableStateOf("Working…")
    var matching by mutableStateOf(false)
    var saving by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)
    var toast by mutableStateOf<String?>(null)

    var retailFormat by mutableStateOf("Medivision")
    var serverFormat by mutableStateOf("Auto")
    var csvRawText by mutableStateOf("")
    var fileName by mutableStateOf<String?>(null)

    var invoiceNo by mutableStateOf("")
    var invoiceDate by mutableStateOf(LocalDate.now().toString())
    var invoiceDup by mutableStateOf<String?>(null)
    var supplierId by mutableIntStateOf(0)
    var supplierName by mutableStateOf("")
    var cashCredit by mutableStateOf("Credit")
    var cdMode by mutableStateOf("Percentage")
    var grossBillAmt by mutableDoubleStateOf(0.0)
    var cdAmtInput by mutableDoubleStateOf(0.0)
    var cdPct by mutableDoubleStateOf(0.0)
    var debitNoteNo by mutableStateOf("")
    var debitAmt by mutableDoubleStateOf(0.0)
    var headerExpanded by mutableStateOf(true)

    var lines by mutableStateOf<List<ImportLine>>(emptyList())
    var reportFilename by mutableStateOf("")
    var reportFormat by mutableStateOf("")
    var reportRequested by mutableStateOf("")
    var reportAiFallback by mutableStateOf(false)
    var reportAiError by mutableStateOf<String?>(null)
    var reportSource by mutableStateOf("Upload")
    var lineFilter by mutableStateOf("All")

    /** Content URI string for TakePicture; survives process recreation via ViewModel. */
    var cameraUri by mutableStateOf<String?>(null)
    var gmailAwaitingOauth by mutableStateOf(false)

    /** Set when AI extract finishes while UI was destroyed; screen applies on next composition. */
    var pendingAiDraft by mutableStateOf<JsonObject?>(null)
    var pendingAiSource by mutableStateOf<String?>(null)
    var pendingAiRequested by mutableStateOf<String?>(null)
    var pendingAiName by mutableStateOf<String?>(null)

    /** In-flight AI / parse job on ViewModel scope — not Compose state. */
    var workJob: Job? = null

    fun hasReport(): Boolean = reportFilename.isNotBlank() || reportFormat.isNotBlank()

    /**
     * Cancel any in-flight job and clear busy/matching/saving.
     * Must run before starting new work — otherwise replacing a job leaves the old flag stuck
     * (e.g. Upload CSV → Match left `busy=true`, or Match → Save left `matching=true`).
     */
    fun resetWorkLocks() {
        workJob?.cancel()
        workJob = null
        busy = false
        matching = false
        saving = false
        busyLabel = "Working…"
    }

    /** Stop AI/parse and clear every lock flag so the screen is never permanently stuck. */
    fun cancelWork(message: String? = null) {
        resetWorkLocks()
        if (message != null) error = message
    }

    /** If lock flags were left true with no live job (leave mid-save / process death), recover. */
    fun recoverStuckBusy() {
        val live = workJob?.isActive == true
        if (!live) {
            if (busy) busy = false
            if (matching) matching = false
            if (saving) saving = false
            busyLabel = "Working…"
        }
    }

    /** Wipe the draft. [cancelInFlight] false when the completing save job must finish its finally. */
    fun clearBill(cancelInFlight: Boolean = true) {
        if (cancelInFlight) cancelWork()
        else {
            busy = false
            matching = false
            // leave saving to the save job's finally
            busyLabel = "Working…"
        }
        lines = emptyList()
        reportFilename = ""
        reportFormat = ""
        reportRequested = ""
        reportAiFallback = false
        reportAiError = null
        reportSource = panel
        fileName = null
        csvRawText = ""
        invoiceNo = ""
        debitNoteNo = ""
        debitAmt = 0.0
        invoiceDup = null
        verify = false
        error = null
        lineFilter = "All"
        clearPendingAi()
    }

    fun clearPendingAi() {
        pendingAiDraft = null
        pendingAiSource = null
        pendingAiRequested = null
        pendingAiName = null
    }
}

package `in`.paperlesspharmacy.advanced.data

import com.google.gson.JsonArray
import com.google.gson.JsonObject

/** One row in the User Rights matrix (admin UI). */
data class RightsModuleRow(
    val moduleCode: String,
    val moduleName: String,
    var canView: Boolean = false,
    var canEdit: Boolean = false,
    var canDelete: Boolean = false,
)

class UserRightsRepository(
    private val api: ApiClient,
    private val session: SessionStore,
) {
    private var ready = false
    private var isAdmin = false
    private val map = mutableMapOf<String, ModuleRight>()

    fun reset() {
        ready = false
        isAdmin = false
        map.clear()
    }

    fun isReady(): Boolean = ready

    fun hasFullAccess(): Boolean {
        if (isAdmin) return true
        return isAdminRole(session.user?.role)
    }

    fun canView(code: String): Boolean {
        if (hasFullAccess()) return true
        val r = map[code] ?: return false
        return r.canView || r.canEdit || r.canDelete
    }

    fun canEdit(code: String): Boolean {
        if (hasFullAccess()) return true
        return map[code]?.canEdit == true
    }

    fun canDelete(code: String): Boolean {
        if (hasFullAccess()) return true
        return map[code]?.canDelete == true
    }

    suspend fun loadMyRights() {
        if (isAdminRole(session.user?.role)) {
            isAdmin = true
        }
        val root = api.get("user-rights/me")
        val data = root.obj("data")
        isAdmin = data.bool("is_admin") || isAdminRole(session.user?.role)
        val rights = data.arr("rights").map { el ->
            val o = el.asJsonObject
            ModuleRight(
                moduleCode = o.str("module_code"),
                moduleName = o.str("module_name"),
                canView = o.bool("can_view"),
                canEdit = o.bool("can_edit"),
                canDelete = o.bool("can_delete"),
            )
        }
        map.clear()
        rights.forEach { map[it.moduleCode] = it }
        session.saveRightsCache(rights, isAdmin)
        ready = true
    }

    fun hydrateFromCache() {
        isAdmin = session.cachedIsAdmin() || isAdminRole(session.user?.role)
        session.loadRightsCache().forEach { map[it.moduleCode] = it }
        ready = map.isNotEmpty() || isAdmin
    }

    /** Module catalog for admin matrix — collapses Debit/Credit → RETURN_NOTE (web parity). */
    fun loadModules(): List<RightsModuleRow> {
        val byCode = linkedMapOf<String, RightsModuleRow>()
        for (m in api.get("user-rights/modules").dataRows()) {
            var code = m.str("module_code").ifBlank { m.str("code") }.uppercase()
            if (code.isBlank()) continue
            if (code == "DEBIT_NOTE" || code == "CREDIT_NOTE") code = "RETURN_NOTE"
            if (byCode.containsKey(code)) continue
            val name = if (code == "RETURN_NOTE") {
                "Return Note"
            } else {
                m.str("module_name").ifBlank { m.str("name") }.ifBlank { code }
            }
            byCode[code] = RightsModuleRow(code, name)
        }
        return byCode.values.toList()
    }

    fun loadUsers(): List<JsonObject> = api.get("user-rights/users").dataRows()

    fun branches(): List<JsonObject> = api.get("meta/branches").dataRows()

    fun saveUser(body: JsonObject): JsonObject = api.post("user-rights/users", body).obj("data")

    fun deleteUser(id: Int): JsonObject = api.delete("user-rights/users/$id")

    private fun isAdminRole(role: String?): Boolean =
        role == "ho_admin" || role == "branch_admin"
}

/** Merge saved user rights onto the module blank template. */
fun mergeUserRights(template: List<RightsModuleRow>, existing: List<JsonObject>): List<RightsModuleRow> {
    val byCode = mutableMapOf<String, RightsModuleRow>()
    for (r in existing) {
        var code = r.str("module_code").uppercase()
        if (code == "DEBIT_NOTE" || code == "CREDIT_NOTE") code = "RETURN_NOTE"
        if (code.isBlank()) continue
        val prev = byCode[code]
        byCode[code] = RightsModuleRow(
            moduleCode = code,
            moduleName = r.str("module_name").ifBlank { prev?.moduleName ?: code },
            canView = r.bool("can_view") || prev?.canView == true,
            canEdit = r.bool("can_edit") || prev?.canEdit == true,
            canDelete = r.bool("can_delete") || prev?.canDelete == true,
        )
    }
    return template.map { m ->
        val found = byCode[m.moduleCode.uppercase()]
        if (found == null) m.copy()
        else m.copy(canView = found.canView, canEdit = found.canEdit, canDelete = found.canDelete)
    }
}

fun rightsToJsonArray(rows: List<RightsModuleRow>): JsonArray {
    val arr = JsonArray()
    rows.forEach { r ->
        arr.add(JsonObject().apply {
            addProperty("module_code", r.moduleCode)
            addProperty("module_name", r.moduleName)
            addProperty("can_view", if (r.canView) 1 else 0)
            addProperty("can_edit", if (r.canEdit) 1 else 0)
            addProperty("can_delete", if (r.canDelete) 1 else 0)
        })
    }
    return arr
}

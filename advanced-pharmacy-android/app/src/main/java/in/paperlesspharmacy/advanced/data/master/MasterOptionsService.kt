package `in`.paperlesspharmacy.advanced.data.master

import android.content.Context
import com.google.gson.JsonObject
import com.google.gson.JsonArray
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.master.MasterFormLogic.optionValue
import `in`.paperlesspharmacy.advanced.data.jInt
import `in`.paperlesspharmacy.advanced.data.str
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

data class MasterOption(val value: String, val label: String, val extra: String? = null)

class MasterOptionsService(
    private val context: Context,
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    private val cache = mutableMapOf<String, List<MasterOption>>()

    suspend fun optionsFor(field: MasterFieldDto, config: MasterConfigDto): List<MasterOption> {
        field.options?.takeIf { it.isNotEmpty() }?.let { opts ->
            return opts.map { MasterOption(optionValue(it), it.label) }
        }
        val source = field.optionSource ?: return emptyList()
        cache[source]?.let { return it }
        val loaded = withContext(Dispatchers.IO) { loadSource(source, config) }
        cache[source] = loaded
        return loaded
    }

    fun invalidate() = cache.clear()

    private fun loadSource(source: String, config: MasterConfigDto): List<MasterOption> = when (source) {
        "schedule" -> listOf(
            MasterOption("OTC", "OTC - Over The Counter"),
            MasterOption("H", "H - Schedule H"),
            MasterOption("H1", "H1 - Schedule H1"),
            MasterOption("X", "X - Schedule X"),
            MasterOption("G", "G - Schedule G"),
            MasterOption("RESTRICTED", "RESTRICTED"),
        )
        "gstStates" -> MasterConfigLoader.loadGstStates(context).map { MasterOption(it.value, it.label) }
        "branches" -> api.get("meta/branches").dataRows().map {
            MasterOption(it.jInt("id").toString(), "${it.str("code")} - ${it.str("name")}")
        }
        "roles" -> api.get("meta/roles").dataRows().map {
            MasterOption(it.str("name"), it.str("label").ifBlank { it.str("name") })
        }
        "generics" -> crud.list("generics", perPage = 5000).rows.map {
            MasterOption(it.jInt("id").toString(), it.str("name"))
        }
        "manufacturers" -> crud.list("manufacturers", perPage = 5000).rows.map {
            val name = it.str("name")
            val sc = it.str("s_code")
            MasterOption(name, if (sc.isNotBlank()) "$name ($sc)" else name, sc)
        }
        "packSizes" -> {
            val el = api.get("meta/pack-sizes").get("data") ?: return emptyList()
            val arr: JsonArray = when {
                el.isJsonArray -> el.asJsonArray
                el.isJsonObject && el.asJsonObject.has("packs") -> el.asJsonObject.getAsJsonArray("packs")
                else -> return emptyList()
            }
            (0 until arr.size()).map { i ->
                val item = arr.get(i)
                when {
                    item.isJsonObject -> {
                        val o = item.asJsonObject
                        val pack = o.str("pack").ifBlank { o.str("pack_size").ifBlank { o.str("value") } }
                        MasterOption(pack, o.str("label").ifBlank { pack })
                    }
                    item.isJsonPrimitive -> {
                        val pack = item.asString
                        MasterOption(pack, pack)
                    }
                    else -> MasterOption("", "")
                }
            }.filter { it.value.isNotBlank() }
        }
        else -> emptyList()
    }

    suspend fun warmUpForConfig(config: MasterConfigDto) {
        val sources = buildSet {
            config.fields.mapNotNull { it.optionSource }.forEach { add(it) }
            config.listFilters?.forEach { f ->
                if (f.source == "schedule") add("schedule")
            }
        }
        sources.forEach { src ->
            val dummy = MasterFieldDto(key = "", label = "", type = "select", optionSource = src)
            optionsFor(dummy, config)
        }
    }
}

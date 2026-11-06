package `in`.paperlesspharmacy.advanced.data

import com.google.gson.JsonObject

class CrudRepository(private val api: ApiClient) {
    fun list(
        path: String,
        search: String = "",
        page: Int = 1,
        perPage: Int = 25,
        filters: Map<String, String> = emptyMap(),
    ): PaginatedRows {
        val q = mutableMapOf(
            "page" to page.toString(),
            "per_page" to perPage.toString(),
        )
        if (search.isNotBlank()) q["search"] = search
        filters.forEach { (k, v) -> if (v.isNotBlank()) q[k] = v }
        val root = api.get(path, q)
        return paginatedFromApiData(root.obj("data"))
    }

    fun get(path: String, id: Int): JsonObject =
        api.get("$path/$id").obj("data")

    fun create(path: String, body: JsonObject) {
        api.post(path, body)
    }

    fun update(path: String, id: Int, body: JsonObject) {
        api.put("$path/$id", body)
    }

    fun delete(path: String, id: Int) {
        api.delete("$path/$id")
    }

    fun fetch(path: String, query: Map<String, String> = emptyMap()): JsonObject =
        api.get(path, query).obj("data")
}

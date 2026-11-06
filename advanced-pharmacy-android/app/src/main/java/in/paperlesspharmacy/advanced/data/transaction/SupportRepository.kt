package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.MultipartFile
import `in`.paperlesspharmacy.advanced.data.arr
import `in`.paperlesspharmacy.advanced.data.bool
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.optInt
import `in`.paperlesspharmacy.advanced.data.str

data class SupportListResult(
    val isAdmin: Boolean,
    val tickets: List<JsonObject>,
    val limitedLog: Boolean,
    val logLimit: Int?,
    val statuses: List<String>,
)

data class SupportConversation(
    val ticket: JsonObject,
    val replies: List<JsonObject>,
    val canReply: Boolean,
)

data class SupportReplyResult(
    val reply: JsonObject,
    val reopened: Boolean,
    val ticket: JsonObject,
)

data class SupportPollResult(
    val isAdmin: Boolean,
    val notifications: List<JsonObject>,
    val serverTime: String,
)

class SupportRepository(private val api: ApiClient) {
    fun list(status: String = "", search: String = ""): SupportListResult {
        val q = mutableMapOf<String, String>()
        if (status.isNotBlank()) q["status"] = status
        if (search.isNotBlank()) q["search"] = search
        val data = api.get("support/tickets", q).obj("data")
        val tickets = data.arr("tickets")
        val statuses = data.arr("statuses")
        return SupportListResult(
            isAdmin = data.bool("is_admin"),
            tickets = (0 until tickets.size()).map { tickets.get(it).asJsonObject },
            limitedLog = data.bool("limited_log"),
            logLimit = data.optInt("log_limit"),
            statuses = (0 until statuses.size()).mapNotNull {
                statuses.get(it)?.takeIf { e -> !e.isJsonNull }?.asString
            },
        )
    }

    fun create(
        subject: String,
        queryText: String,
        screenshots: List<MultipartFile>,
    ): JsonObject {
        val root = api.postMultipartMany(
            path = "support/tickets",
            formFields = mapOf(
                "subject" to subject,
                "query_text" to queryText,
            ),
            files = screenshots.map { it.copy(field = "screenshots[]") },
        )
        return root.obj("data")
    }

    fun conversation(id: Int): SupportConversation {
        val data = api.get("support/tickets/$id/replies").obj("data")
        val replies = data.arr("replies")
        return SupportConversation(
            ticket = data.obj("ticket"),
            replies = (0 until replies.size()).map { replies.get(it).asJsonObject },
            canReply = data.bool("can_reply"),
        )
    }

    fun postReply(
        id: Int,
        message: String,
        shots: List<MultipartFile>,
        files: List<MultipartFile>,
    ): SupportReplyResult {
        val root = api.postMultipartMany(
            path = "support/tickets/$id/replies",
            formFields = mapOf("message" to message),
            files = shots.map { it.copy(field = "reply_screenshots[]") } +
                files.map { it.copy(field = "reply_files[]") },
        )
        val data = root.obj("data")
        return SupportReplyResult(
            reply = data.obj("reply"),
            reopened = data.bool("reopened"),
            ticket = data.obj("ticket"),
        )
    }

    fun updateStatus(
        id: Int,
        status: String,
        adminRemark: String,
        shots: List<MultipartFile>,
        files: List<MultipartFile>,
    ): JsonObject {
        val root = api.postMultipartMany(
            path = "support/tickets/$id/status",
            formFields = mapOf(
                "status" to status,
                "admin_remark" to adminRemark,
            ),
            files = shots.map { it.copy(field = "admin_screenshots[]") } +
                files.map { it.copy(field = "admin_files[]") },
        )
        return root.obj("data").obj("ticket")
    }

    fun poll(since: String): SupportPollResult {
        val q = if (since.isBlank()) emptyMap() else mapOf("since" to since)
        val data = api.get("support/poll", q).obj("data")
        val notes = data.arr("notifications")
        return SupportPollResult(
            isAdmin = data.bool("is_admin"),
            notifications = (0 until notes.size()).map { notes.get(it).asJsonObject },
            serverTime = data.str("server_time"),
        )
    }

    /** Relative API path from ticket/reply payload, e.g. `/support/tickets/5/files/x.png`. */
    fun fileBytes(apiRelativeUrl: String): ByteArray {
        val path = apiRelativeUrl.trimStart('/').removePrefix("api/")
        return api.getBytes(path)
    }
}

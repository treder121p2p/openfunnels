import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// ---------------------------------------------------------------------------
// Configuration — set via environment variables
// ---------------------------------------------------------------------------
const OPENFUNNELS_URL = process.env.OPENFUNNELS_URL || "http://localhost:8000";
const OPENFUNNELS_TOKEN = process.env.OPENFUNNELS_TOKEN || "";

if (!OPENFUNNELS_TOKEN) {
  console.error(
    "ERROR: OPENFUNNELS_TOKEN is required. Generate one via:\n" +
      "  curl -X POST http://localhost:8000/api/token -H 'Authorization: Bearer <session>'"
  );
  process.exit(1);
}

// ---------------------------------------------------------------------------
// API helper
// ---------------------------------------------------------------------------
async function api(path, options = {}) {
  const url = `${OPENFUNNELS_URL}/api${path}`;
  const res = await fetch(url, {
    ...options,
    headers: {
      Authorization: `Bearer ${OPENFUNNELS_TOKEN}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...options.headers,
    },
  });

  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    data = { raw: text };
  }

  if (!res.ok) {
    throw new Error(`API ${res.status}: ${JSON.stringify(data)}`);
  }
  return data;
}

// ---------------------------------------------------------------------------
// MCP Server
// ---------------------------------------------------------------------------
const server = new McpServer({
  name: "openfunnels",
  version: "1.0.0",
});

// --- Funnels ------------------------------------------------------------

server.tool(
  "list_funnels",
  "List all funnels in OpenFunnels",
  {},
  async () => {
    const data = await api("/funnels");
    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(data.funnels, null, 2),
        },
      ],
    };
  }
);

server.tool(
  "get_funnel",
  "Get a funnel with full content (sections, columns, blocks)",
  {
    funnel_id: z.number().describe("Funnel ID"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}`);
    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(data, null, 2),
        },
      ],
    };
  }
);

server.tool(
  "create_funnel",
  "Create a new funnel",
  {
    name: z.string().describe("Funnel name"),
    description: z.string().optional().describe("Description"),
    content: z
      .object({
        sections: z.array(z.any()),
      })
      .optional()
      .describe("Funnel content (sections with columns and blocks)"),
    settings: z
      .object({
        backgroundColor: z.string().optional(),
        maxWidth: z.string().optional(),
        fontFamily: z.string().optional(),
      })
      .optional()
      .describe("Funnel-level settings"),
  },
  async ({ name, description, content, settings }) => {
    const data = await api("/funnels", {
      method: "POST",
      body: JSON.stringify({ name, description, content, settings }),
    });
    return {
      content: [
        {
          type: "text",
          text: `Funnel created:\n- ID: ${data.id}\n- Name: ${data.name}\n- Slug: ${data.slug}\n- Status: ${data.status}`,
        },
      ],
    };
  }
);

server.tool(
  "update_funnel",
  "Update a funnel (name, description, content, settings)",
  {
    funnel_id: z.number().describe("Funnel ID"),
    name: z.string().optional().describe("New name"),
    description: z.string().optional().describe("New description"),
    content: z
      .object({
        sections: z.array(z.any()),
      })
      .optional()
      .describe("New content"),
    settings: z
      .object({
        backgroundColor: z.string().optional(),
        maxWidth: z.string().optional(),
        fontFamily: z.string().optional(),
      })
      .optional()
      .describe("New settings"),
  },
  async ({ funnel_id, name, description, content, settings }) => {
    const body = {};
    if (name !== undefined) body.name = name;
    if (description !== undefined) body.description = description;
    if (content !== undefined) body.content = content;
    if (settings !== undefined) body.settings = settings;

    const data = await api(`/funnels/${funnel_id}`, {
      method: "PUT",
      body: JSON.stringify(body),
    });
    return {
      content: [
        {
          type: "text",
          text: `Funnel updated:\n- ID: ${data.id}\n- Name: ${data.name}\n- Slug: ${data.slug}\n- Updated: ${data.updated_at}`,
        },
      ],
    };
  }
);

server.tool(
  "delete_funnel",
  "Delete a funnel",
  {
    funnel_id: z.number().describe("Funnel ID"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}`, { method: "DELETE" });
    return {
      content: [{ type: "text", text: data.message }],
    };
  }
);

server.tool(
  "publish_funnel",
  "Publish a funnel (makes it publicly accessible)",
  {
    funnel_id: z.number().describe("Funnel ID"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}/publish`, {
      method: "POST",
    });
    return {
      content: [
        {
          type: "text",
          text: `${data.message}\nPublic URL: ${data.public_url}`,
        },
      ],
    };
  }
);

server.tool(
  "unpublish_funnel",
  "Unpublish a funnel (revert to draft)",
  {
    funnel_id: z.number().describe("Funnel ID"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}/unpublish`, {
      method: "POST",
    });
    return {
      content: [{ type: "text", text: data.message }],
    };
  }
);

server.tool(
  "duplicate_funnel",
  "Create a copy of an existing funnel",
  {
    funnel_id: z.number().describe("Funnel ID to duplicate"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}/duplicate`, {
      method: "POST",
    });
    return {
      content: [
        {
          type: "text",
          text: `Funnel duplicated:\n- ID: ${data.id}\n- Name: ${data.name}\n- Slug: ${data.slug}`,
        },
      ],
    };
  }
);

// --- AI Generation ------------------------------------------------------

server.tool(
  "generate_funnel",
  "Generate a funnel using AI based on a brief",
  {
    goal: z.string().describe("Funnel goal (max 100 chars)"),
    business: z.string().describe("Business description (max 500 chars)"),
    audience: z.string().describe("Target audience (max 500 chars)"),
    offer: z.string().describe("The offer / value proposition (max 1000 chars)"),
    tone: z
      .string()
      .describe("Tone of voice (e.g. professional, casual, urgent)"),
  },
  async ({ goal, business, audience, offer, tone }) => {
    const data = await api("/funnels/generate", {
      method: "POST",
      body: JSON.stringify({ goal, business, audience, offer, tone }),
    });

    if (data.error) {
      return { content: [{ type: "text", text: `Error: ${data.error}` }] };
    }

    return {
      content: [
        {
          type: "text",
          text: `AI-generated funnel content:\n${JSON.stringify(data.funnel, null, 2)}`,
        },
      ],
    };
  }
);

// --- Templates ----------------------------------------------------------

server.tool(
  "export_template",
  "Export a funnel as a .openfunnels.json template",
  {
    funnel_id: z.number().describe("Funnel ID"),
  },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}/template`);
    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(data, null, 2),
        },
      ],
    };
  }
);

server.tool(
  "import_template",
  "Import a template and create a new funnel from it",
  {
    template: z
      .object({
        kind: z.literal("openfunnels-template"),
        schemaVersion: z.literal(1),
        metadata: z.object({
          name: z.string(),
          description: z.string().optional(),
          category: z.string(),
          tags: z.array(z.string()).optional(),
        }),
        funnel: z.object({
          content: z.object({
            sections: z.array(z.any()),
          }),
          settings: z.object({
            backgroundColor: z.string(),
            maxWidth: z.string(),
          }),
        }),
      })
      .describe("Template JSON (openfunnels-template format)"),
  },
  async ({ template }) => {
    const data = await api("/templates/import", {
      method: "POST",
      body: JSON.stringify({ template }),
    });
    return {
      content: [
        {
          type: "text",
          text: `${data.message}\n- ID: ${data.id}\n- Name: ${data.name}\n- Slug: ${data.slug}`,
        },
      ],
    };
  }
);

// --- Start ---------------------------------------------------------------

const transport = new StdioServerTransport();
await server.connect(transport);
console.error("OpenFunnels MCP server running on stdio");

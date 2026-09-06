import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync } from "fs";
import { join } from "path";

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const OPENFUNNELS_URL = process.env.OPENFUNNELS_URL || "http://localhost:8000";
const OPENFUNNELS_TOKEN = process.env.OPENFUNNELS_TOKEN || "";
const TEMPLATES_DIR = process.env.TEMPLATES_DIR || join(process.cwd(), "templates");

if (!OPENFUNNELS_TOKEN) {
  console.error("ERROR: OPENFUNNELS_TOKEN is required.");
  process.exit(1);
}

if (!existsSync(TEMPLATES_DIR)) mkdirSync(TEMPLATES_DIR, { recursive: true });

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
      ...(options.headers || {}),
    },
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { raw: text }; }
  if (!res.ok) throw new Error(`API ${res.status}: ${JSON.stringify(data)}`);
  return data;
}

// ---------------------------------------------------------------------------
// Template builder helpers
// ---------------------------------------------------------------------------
let _id = 0;
function uid(prefix) { return `${prefix}-${++_id}-${Date.now().toString(36)}`; }

function block(type, content, settings = {}) {
  return {
    id: uid("block"), type, content,
    settings: { padding: "8px", margin: "0", backgroundColor: "transparent", borderRadius: "0px", ...settings },
  };
}

function column(width, blocks, settings = {}) {
  return {
    id: uid("column"), type: "column", width, blocks,
    settings: { padding: "12px", backgroundColor: "transparent", verticalAlign: "middle", ...settings },
  };
}

function section(layout, columns, settings = {}) {
  return {
    id: uid("section"), type: "section", layout, columns,
    settings: { backgroundColor: "#ffffff", padding: "72px 48px", margin: "0px", minHeight: "auto", fullWidth: false, ...settings },
  };
}

// ---------------------------------------------------------------------------
// Template generators
// ---------------------------------------------------------------------------
function generateLandingPage(d) {
  const accent = d.accentColor || "#3b82f6";
  const sections = [];
  sections.push(section("single", [
    column(100, [
      block("text", { text: d.title, fontSize: "48px", color: "#111827", fontWeight: "800", textAlign: "center" }),
      block("text", { text: d.subtitle, fontSize: "20px", color: "#6b7280", textAlign: "center" }),
      block("button", { text: d.ctaText, url: d.ctaUrl || "#", backgroundColor: accent, color: "#ffffff", borderRadius: "8px" }),
    ]),
  ], { backgroundColor: d.bgColor || "#ffffff", padding: "100px 48px 80px" }));

  if (d.features && d.features.length > 0) {
    const n = Math.min(d.features.length, 4);
    sections.push(section("single",
      d.features.slice(0, n).map(f => column(100 / n, [block("text", { text: f, fontSize: "18px", color: "#111827", fontWeight: "600", textAlign: "center" })])),
      { backgroundColor: "#f9fafb", padding: "64px 48px" }
    ));
  }

  if (d.testimonial) {
    sections.push(section("single", [
      column(100, [
        block("text", { text: `"${d.testimonial.text}"`, fontSize: "22px", color: "#374151", fontStyle: "italic", textAlign: "center" }),
        block("text", { text: `\u2014 ${d.testimonial.author}, ${d.testimonial.role}`, fontSize: "16px", color: "#6b7280", textAlign: "center" }),
      ]),
    ], { padding: "64px 48px" }));
  }

  return { content: { sections }, settings: { backgroundColor: d.bgColor || "#ffffff", maxWidth: "1200px" } };
}

function generateLeadCapture(d) {
  const sections = [];
  sections.push(section("two-column-66-33", [
    column(66, [
      block("text", { text: d.headline, fontSize: "44px", color: "#111827", fontWeight: "800" }),
      block("text", { text: d.subheadline, fontSize: "18px", color: "#4b5563" }),
    ]),
    column(34, [
      block("form", {
        title: d.formTitle, placeholder: "Email address", namePlaceholder: "Full name",
        buttonText: d.ctaText || "Get started", successMessage: "Thank you! Check your inbox.",
      }, { padding: "28px", backgroundColor: "#f9fafb", borderRadius: "12px" }),
    ]),
  ], { padding: "80px 48px" }));

  if (d.benefits && d.benefits.length > 0) {
    sections.push(section("three-column",
      d.benefits.slice(0, 3).map(b => column(33.33, [block("text", { text: b, fontSize: "18px", color: "#111827", fontWeight: "600" })])),
      { backgroundColor: "#f3f4f6", padding: "48px" }
    ));
  }
  return { content: { sections }, settings: { backgroundColor: "#ffffff", maxWidth: "1200px" } };
}

function generateWebinarPage(d) {
  const sections = [];
  sections.push(section("single", [
    column(100, [
      block("text", { text: "Live Training", fontSize: "14px", color: "#f97316", fontWeight: "700", textAlign: "center" }),
      block("text", { text: d.title, fontSize: "42px", color: "#111827", fontWeight: "800", textAlign: "center" }),
      block("text", { text: d.date, fontSize: "18px", color: "#4b5563", textAlign: "center" }),
    ]),
  ], { padding: "80px 48px 40px" }));

  sections.push(section("two-column", [
    column(55, [
      block("text", { text: "What you will learn", fontSize: "28px", color: "#111827", fontWeight: "800" }),
      block("text", { text: d.agenda.map((a, i) => `${i + 1}. ${a}`).join("\n"), fontSize: "17px", color: "#374151" }),
    ]),
    column(45, [
      block("form", {
        title: d.formTitle || "Reserve your seat", placeholder: "Email address",
        namePlaceholder: "Full name", buttonText: "Register now", successMessage: "You are registered!",
      }, { padding: "28px", backgroundColor: "#f9fafb", borderRadius: "12px" }),
    ]),
  ], { padding: "48px" }));

  if (d.speakerName) {
    sections.push(section("single", [
      column(100, [
        block("text", { text: `Speaker: ${d.speakerName}`, fontSize: "22px", color: "#111827", fontWeight: "700", textAlign: "center" }),
        ...(d.speakerBio ? [block("text", { text: d.speakerBio, fontSize: "16px", color: "#6b7280", textAlign: "center" })] : []),
      ]),
    ], { backgroundColor: "#f9fafb", padding: "48px" }));
  }
  return { content: { sections }, settings: { backgroundColor: "#ffffff", maxWidth: "1200px" } };
}

function generateProductPage(d) {
  const accent = d.accentColor || "#10b981";
  const sections = [];
  sections.push(section("two-column", [
    column(55, [
      block("text", { text: "New Release", fontSize: "14px", color: accent, fontWeight: "700" }),
      block("text", { text: d.name, fontSize: "42px", color: "#111827", fontWeight: "800" }),
      block("text", { text: d.tagline, fontSize: "18px", color: "#4b5563" }),
      block("button", { text: d.ctaText || "Buy now", url: "#", backgroundColor: accent, color: "#ffffff", borderRadius: "8px" }),
    ]),
    column(45, [block("text", { text: d.description, fontSize: "16px", color: "#374151" })]),
  ], { padding: "80px 48px" }));

  if (d.features && d.features.length > 0) {
    sections.push(section("three-column",
      d.features.slice(0, 3).map(f => column(33.33, [block("text", { text: f, fontSize: "18px", color: "#111827", fontWeight: "600" })])),
      { backgroundColor: "#f9fafb", padding: "48px" }
    ));
  }

  if (d.testimonial) {
    sections.push(section("single", [
      column(100, [
        block("text", { text: `"${d.testimonial.text}"`, fontSize: "20px", color: "#374151", fontStyle: "italic", textAlign: "center" }),
        block("text", { text: `\u2014 ${d.testimonial.author}`, fontSize: "16px", color: "#6b7280", textAlign: "center" }),
      ]),
    ], { padding: "48px" }));
  }
  return { content: { sections }, settings: { backgroundColor: d.bgColor || "#ffffff", maxWidth: "1200px" } };
}

function generateServicePage(d) {
  const sections = [];
  sections.push(section("single", [
    column(100, [
      block("text", { text: d.name, fontSize: "14px", color: "#3b82f6", fontWeight: "700", textAlign: "center" }),
      block("text", { text: d.headline, fontSize: "42px", color: "#111827", fontWeight: "800", textAlign: "center" }),
    ]),
  ], { padding: "80px 48px 40px" }));

  sections.push(section("three-column",
    d.services.slice(0, 3).map(s => column(33.33, [
      block("text", { text: s.title, fontSize: "20px", color: "#111827", fontWeight: "700" }),
      block("text", { text: s.description, fontSize: "16px", color: "#6b7280" }),
    ])),
    { backgroundColor: "#f9fafb", padding: "48px" }
  ));

  sections.push(section("single", [
    column(100, [
      block("button", { text: d.ctaText || "Contact us", url: d.phone ? `tel:${d.phone}` : "#", backgroundColor: "#3b82f6", color: "#ffffff", borderRadius: "8px" }),
    ]),
  ], { padding: "48px" }));
  return { content: { sections }, settings: { backgroundColor: d.bgColor || "#ffffff", maxWidth: "1200px" } };
}

// ---------------------------------------------------------------------------
// MCP Server
// ---------------------------------------------------------------------------
const server = new McpServer({ name: "openfunnels", version: "2.0.0" });

// --- Template generation ---

server.tool("create_template",
  "Generate a page template. Types: landing, lead-capture, webinar, product, service.",
  {
    templateType: z.enum(["landing", "lead-capture", "webinar", "product", "service"]),
    name: z.string().describe("Template name"),
    data: z.record(z.any()).describe("Template fields (depends on type)"),
    saveToFile: z.boolean().optional().describe("Save to templates/ dir (default true)"),
  },
  async ({ templateType, name, data, saveToFile }) => {
    let result;
    switch (templateType) {
      case "landing": result = generateLandingPage(data); break;
      case "lead-capture": result = generateLeadCapture(data); break;
      case "webinar": result = generateWebinarPage(data); break;
      case "product": result = generateProductPage(data); break;
      case "service": result = generateServicePage(data); break;
    }

    const manifest = {
      kind: "openfunnels-template", schemaVersion: 1,
      metadata: { name, description: `${templateType} template`, category: templateType, tags: [templateType] },
      funnel: { content: result.content, settings: result.settings },
    };

    if (saveToFile !== false) {
      const filename = `${name.toLowerCase().replace(/[^a-z0-9]+/g, "-")}.openfunnels.json`;
      const filepath = join(TEMPLATES_DIR, filename);
      writeFileSync(filepath, JSON.stringify(manifest, null, 2), "utf-8");
      return { content: [{ type: "text", text: `Template "${name}" saved to:\n${filepath}\nSections: ${result.content.sections.length}` }] };
    }
    return { content: [{ type: "text", text: JSON.stringify(manifest, null, 2) }] };
  }
);

server.tool("list_templates", "List all saved templates", {}, async () => {
  if (!existsSync(TEMPLATES_DIR)) return { content: [{ type: "text", text: "No templates dir." }] };
  const files = readdirSync(TEMPLATES_DIR).filter(f => f.endsWith(".openfunnels.json"));
  if (!files.length) return { content: [{ type: "text", text: "No templates yet." }] };
  const list = files.map(f => {
    try {
      const d = JSON.parse(readFileSync(join(TEMPLATES_DIR, f), "utf-8"));
      return { file: f, name: d.metadata?.name, category: d.metadata?.category, sections: d.funnel?.content?.sections?.length || 0 };
    } catch { return { file: f, name: "error" }; }
  });
  return { content: [{ type: "text", text: JSON.stringify(list, null, 2) }] };
});

server.tool("import_template_to_openfunnels", "Import saved template as a new funnel in OpenFunnels",
  { filename: z.string() },
  async ({ filename }) => {
    const filepath = join(TEMPLATES_DIR, filename);
    if (!existsSync(filepath)) return { content: [{ type: "text", text: `Not found: ${filename}` }] };
    const template = JSON.parse(readFileSync(filepath, "utf-8"));
    const data = await api("/templates/import", { method: "POST", body: JSON.stringify({ template }) });
    return { content: [{ type: "text", text: `Imported: ID=${data.id}, Name="${data.name}", Slug=${data.slug}` }] };
  }
);

// --- CRUD ---

server.tool("list_funnels", "List all funnels", {}, async () => {
  const data = await api("/funnels");
  return { content: [{ type: "text", text: JSON.stringify(data.funnels, null, 2) }] };
});

server.tool("get_funnel", "Get funnel with full content",
  { funnel_id: z.number() },
  async ({ funnel_id }) => {
    const data = await api(`/funnels/${funnel_id}`);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool("create_funnel", "Create a new funnel",
  { name: z.string(), description: z.string().optional(), content: z.object({ sections: z.array(z.any()) }).optional(), settings: z.record(z.any()).optional() },
  async ({ name, description, content, settings }) => {
    const data = await api("/funnels", { method: "POST", body: JSON.stringify({ name, description, content, settings }) });
    return { content: [{ type: "text", text: `Created: ID=${data.id}, Name="${data.name}", Slug=${data.slug}` }] };
  }
);

server.tool("update_funnel", "Update a funnel",
  { funnel_id: z.number(), name: z.string().optional(), content: z.object({ sections: z.array(z.any()) }).optional(), settings: z.record(z.any()).optional() },
  async ({ funnel_id, ...rest }) => {
    const body = {}; for (const [k, v] of Object.entries(rest)) { if (v !== undefined) body[k] = v; }
    const data = await api(`/funnels/${funnel_id}`, { method: "PUT", body: JSON.stringify(body) });
    return { content: [{ type: "text", text: `Updated: ID=${data.id}` }] };
  }
);

server.tool("delete_funnel", "Delete a funnel", { funnel_id: z.number() }, async ({ funnel_id }) => {
  const data = await api(`/funnels/${funnel_id}`, { method: "DELETE" });
  return { content: [{ type: "text", text: data.message }] };
});

server.tool("publish_funnel", "Publish a funnel", { funnel_id: z.number() }, async ({ funnel_id }) => {
  const data = await api(`/funnels/${funnel_id}/publish`, { method: "POST" });
  return { content: [{ type: "text", text: `${data.message}\nURL: ${data.public_url}` }] };
});

server.tool("unpublish_funnel", "Unpublish a funnel", { funnel_id: z.number() }, async ({ funnel_id }) => {
  const data = await api(`/funnels/${funnel_id}/unpublish`, { method: "POST" });
  return { content: [{ type: "text", text: data.message }] };
});

server.tool("duplicate_funnel", "Duplicate a funnel", { funnel_id: z.number() }, async ({ funnel_id }) => {
  const data = await api(`/funnels/${funnel_id}/duplicate`, { method: "POST" });
  return { content: [{ type: "text", text: `Duplicated: ID=${data.id}` }] };
});

server.tool("generate_funnel", "AI-generate a funnel",
  { goal: z.string(), business: z.string(), audience: z.string(), offer: z.string(), tone: z.string() },
  async (args) => {
    const data = await api("/funnels/generate", { method: "POST", body: JSON.stringify(args) });
    if (data.error) return { content: [{ type: "text", text: `Error: ${data.error}` }] };
    return { content: [{ type: "text", text: JSON.stringify(data.funnel, null, 2) }] };
  }
);

// --- Start ---
const transport = new StdioServerTransport();
await server.connect(transport);
console.error(`OpenFunnels MCP v2.0 running (templates: ${TEMPLATES_DIR})`);

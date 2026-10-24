# ODSI Forge — concept and architecture (draft)

An AI-native industrial design and solid modeling tool: describe or sketch a
product, edit it in a Blender/Rhino-style viewport inside the artifact panel,
check it against real manufacturing constraints, and send it to a maker, all
from Claude Code.

> **Scope note.** This is a separate product from the WordPress plugins in this
> repo. It is parked under `docs/specs/` as a concept; it should get its own
> repo (or `apps/forge/`) before code is written. Nothing here touches
> `odsi-lms`, `odsi-social` or `odsi-bridge`.

## 1. Core idea in one paragraph

**CAD as code, with a visual editor on top.** The source of truth is a
parametric *feature tree* stored as text (JSON plus small Python/TS
expressions). A real B-rep solid kernel regenerates geometry from it. Claude
edits the tree through tools; the human edits the same tree by dragging NURBS
control points, handles and dimensions in the viewport. Both produce the same
diffable operations, so every change is reviewable, undoable and attributable.

## 2. Principles

1. **Don't write a kernel.** Use OpenCascade (OCCT) via CadQuery/build123d
   (Python) or `opencascade.js` (browser). Writing booleans and fillets that
   survive real parts is a decade of work, and it is not our differentiator.
2. **Parametric, not mesh.** Meshes (STL/GLB) are render and exchange formats
   only. Editing always happens on the feature tree and B-rep.
3. **Backend solid, frontend friendly.** The viewport is three.js showing
   tessellated B-rep, with NURBS control cages for surfaces. Edits are sent as
   operations; the kernel returns new tessellation and validity status.
4. **Constraints are first-class.** Dimensions, tolerances, materials and
   process rules are part of the model, not a post-hoc report.
5. **Human gate on anything outward-facing.** Quotes can be automatic; placing
   an order or sending files to a vendor needs explicit approval every time.

## 3. System architecture

```
 Claude Code ──MCP──►  forge-server  ◄──WS/HTTP──  Artifact-panel UI
 (agent tools)         │                           (three.js viewport,
                       │                            feature tree, params,
                       ├─ Feature tree store        NURBS control cage)
                       ├─ Geometry service (OCCT)
                       ├─ Sketch/constraint solver
                       ├─ DFM rule engine
                       ├─ Reference library index
                       ├─ Design-intelligence tools (persona, aesthetics)
                       └─ Manufacturing adapters
```

| Component | Choice | Notes |
| --- | --- | --- |
| Kernel | OCCT through build123d | Python gives Claude a natural scripting layer; STEP/IGES/STL in the box |
| Sketch constraints | planegcs (FreeCAD) or SolveSpace solver | Both open source; do not hand-roll |
| Viewport | three.js + custom control-cage layer | Orbit/pan/zoom, snapping, gumball, section planes |
| NURBS editing | OCCT `Geom_BSplineSurface` + control-point ops | Edit surface, then *thicken/knit to solid*; see risks |
| Storage | Files in git: `part.forge.json` + referenced STEP | Diffable, branchable, fits Claude Code workflow |
| Transport | MCP server for agents, WebSocket for the panel | One operation API underneath both |
| Heavy compute | Worker queue (tessellation, DFM, FEA-lite) | Keep UI responsive |

### Operation API (the heart of it)

Every edit, from human or agent, is an operation on the feature tree:

```json
{ "op": "extrude", "sketch": "sk_3", "depth": "wall * 4", "direction": "normal" }
{ "op": "fillet", "edges": {"select": "edges where convexity=convex and on face f_top"}, "radius": 2.0 }
{ "op": "surface_edit", "face": "f_hero", "control_point": [3, 2], "delta": [0, 0, 1.5] }
```

Edges and faces are referenced by **semantic selectors**, not raw indices, to
mitigate the topological naming problem (the reason FreeCAD models break when
upstream features change).

## 4. Reference-model library ("real products as ground truth")

Goal: start from real, dimensioned products and inherit their constraints, for
example a phone for a case design, a standard bearing, a connector, a DIN
rail, a fastener.

Pipeline: **find → license-check → import STEP → extract → index.**

- **Sources.** Prefer ones with clear terms and APIs: TraceParts, vendor
  catalogs that publish STEP (fasteners, bearings, connectors), GrabCAD and
  Thingiverse-style communities where licenses are per-model, and the
  manufacturer's own CAD downloads.
- **License gate.** Every imported model records source URL, license and
  allowed use. "Free to download" is not "free to redistribute or derive".
  Forge refuses to export a derived design that includes a model whose license
  forbids it. Scraping sites against their ToS is out of scope; use APIs,
  official downloads, or user-uploaded files.
- **Extraction.** From the B-rep, derive: bounding box, key dimensions (hole
  patterns, mounting pitch, thread specs), mass properties, mating faces, and
  keep-out volumes. Store as a typed `spec` sidecar so Claude can reason
  "this connector needs a 12.4 x 8.2 mm cutout with 0.2 mm clearance".
- **Use as constraint.** An imported part becomes an *assembly context*: the
  design must clear its keep-out zones and mate to its datums.
- Mesh-only downloads (STL/OBJ) work for visual reference and rough fit
  checks, but not for dimension extraction. Flag them as low-confidence.

## 5. Manufacturing constraints (DFM engine)

Rule packs per process, each rule a function over the B-rep that returns
findings with location, severity and a suggested operation to fix it:

| Process | Example rules |
| --- | --- |
| CNC milling | min internal corner radius vs tool, max pocket depth:width, undercuts, thin walls |
| Injection molding | draft angle, uniform wall thickness, ribs ≤ 60% of wall, undercuts needing side actions, sink risk |
| Sheet metal | min bend radius by material and gauge, hole-to-edge distance, flat-pattern validity |
| FDM / SLA / SLS | min feature, overhang angle, escape holes, orientation |
| Casting | draft, parting line, section thickness changes |

Findings carry a **suggested fix operation**, so Claude (or the user, with one
click) can apply it: "wall at 0.8 mm below the 1.2 mm minimum, thicken?".

Material and tolerance data live in a versioned catalog (YAML) so rules cite
their numbers and are auditable.

## 6. Design intelligence tools (your differentiator)

Exposed as MCP tools and as panels in the UI. They shape the *brief* and
*judge* the result; they do not replace the engineer.

- **Persona generator.** From a product category and market, produce users
  with physical attributes (hand size, reach, grip strength, vision), context
  of use, and goals. Anthropometric data should come from real tables
  (e.g. published percentile datasets) and be cited, not invented. Output feeds
  constraints: grip diameter range, button force, reach envelope.
- **Ergonomic checks.** Hand and reach envelope solids intersected with the
  model: can the 5th-percentile hand span this grip, is the button inside the
  thumb arc.
- **Aesthetics considerations.** Concrete, measurable proxies rather than
  "make it pretty": curvature continuity (G0/G1/G2) between surfaces, highlight
  line and zebra analysis, proportion analysis (golden ratio, rule of thirds
  as options), visual weight balance, form-language consistency across a
  product family, CMF (color, material, finish) palettes tied to brand.
- **Design critique.** Claude reviews renders plus the metrics above and
  explains trade-offs. Always show the numbers behind the opinion.
- **Variant generation.** Parametric families: Claude varies named parameters
  within ranges, the DFM engine culls invalid variants, the persona scorer
  ranks the rest.

## 7. Send for manufacturing

1. **Package.** STEP (precise), STL/3MF (printing), DXF (flat patterns), PDF
   drawing with tolerances, BOM, material, finish, quantity.
2. **Preflight.** DFM must pass for the chosen process, license gate must
   pass, units and tolerances must be explicit.
3. **Quote.** Adapters for instant-quote services (Xometry, Protolabs, Fictiv,
   Craftcloud and similar) behind one interface. Quotes can be fetched freely.
4. **Approval gate.** Sending files, placing an order or spending money
   requires a confirmation step showing exactly what leaves the machine and
   what it costs. Never automatic, never inferred from earlier approval.
5. **Track.** Order status, revisions, and inspection reports back into the
   project, linked to the design revision that was sent.

IP note: files sent to a vendor are shared externally; the UI says so, and
projects can mark parts as "never leaves this machine".

## 8. UI in the artifact panel

- Viewport with orbit, section, exploded view, measure, and a selection
  model that works on faces, edges, vertices and control points.
- Feature tree on the left (reorder, suppress, roll back), parameters table
  on the right, DFM findings and persona overlays as toggleable layers.
- A "surface mode" that shows the NURBS control cage for freeform parts
  (consumer product shells, handles), and a "solid mode" for prismatic work.
- Chat-adjacent actions: select faces, then ask Claude, with the selection
  passed as semantic selectors.
- Cheap first version: static HTML artifact with three.js rendering GLB
  exported by the server. Bidirectional editing comes after the operation
  API is stable.

## 9. Roadmap

| Phase | Deliverable | Exit test |
| --- | --- | --- |
| 0 — Spike (1–2 wks) | build123d script → STEP + GLB; artifact panel renders it | Claude generates a parametric enclosure, user changes one dimension, model regenerates |
| 1 — Kernel service | Feature-tree JSON, operation API, MCP server, undo/redo, semantic selectors | Same model edited by agent and by script produces identical trees |
| 2 — Sketching | 2D sketcher with solver, extrude/revolve/sweep/loft, fillet/chamfer/shell | Model a bracket from constraints only |
| 3 — Reference library | STEP import, spec extraction, license ledger, assembly context | Design a case around an imported phone model with keep-outs |
| 4 — DFM | Injection molding and CNC packs with fix suggestions | Flags and auto-fixes draft and wall thickness on the case |
| 5 — Surfaces | NURBS control-cage editing, continuity analysis, thicken to solid | Sculpt a handle shell, get a valid solid |
| 6 — Design intelligence | Persona, ergonomics, aesthetics metrics, variants | Ranked variants for a handheld product |
| 7 — Manufacturing | Packaging, quote adapters, approval gate, tracking | Quote from one vendor, gated order flow end to end |

## 10. Risks and honest hard parts

- **NURBS to valid solid.** Freeform surfaces that knit into watertight
  solids with fillets is where commercial tools spend their effort. Plan for
  limited surface features first and keep surfaces → solid conversion
  explicit with validity checks.
- **Topological naming.** Semantic selectors reduce but do not eliminate it.
  Budget time for a regeneration test suite.
- **Constraint solver UX.** Over- and under-constrained sketches need clear
  diagnostics, or users and the agent get stuck.
- **Reference-model licensing.** Biggest legal exposure. The license ledger is
  a launch blocker, not a nice-to-have.
- **Trust in AI-generated geometry.** Models can look right and be wrong.
  Every generated feature should come with checks (volume, clearance,
  validity) and the units shown.
- **Not certified engineering.** Structural, safety-critical and regulated
  products need qualified review. Forge should say so wherever it shows
  analysis results.
- **Vendor APIs change** and some quoting services have no public API; adapters
  must degrade to "export package and open vendor page".

## 11. Decisions needed before building

1. Language/runtime for the server: Python (build123d, fastest to start) vs
   TypeScript with `opencascade.js` (everything in one browser-side stack).
   Recommendation: Python server, TS viewer.
2. Repo: standalone `odsi-forge` vs `apps/forge/` here. Recommendation:
   standalone, since it shares nothing with the WordPress plugins.
3. First vertical: consumer product enclosures (fits injection molding, CMF
   and ergonomics, showcases the persona tools) vs prismatic CNC parts.
   Recommendation: enclosures.
4. Reference sources to integrate first, based on license clarity.
5. Which manufacturing vendor to pilot with for quotes.

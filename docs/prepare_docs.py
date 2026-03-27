#!/usr/bin/env python3

from __future__ import annotations

import html
import posixpath
import re
import shutil
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
README_PATH = ROOT / "README.md"
DOCS_SOURCE_DIR = Path(__file__).resolve().parent
GENERATED_DOCS_DIR = DOCS_SOURCE_DIR / ".docs-site"
THEME_ASSETS_DIR = DOCS_SOURCE_DIR / "theme" / "assets"
LOGO_DIR = ROOT / "logo"
REPOSITORY_BLOB_BASE = "https://github.com/loupe-php/loupe/blob/main/"

DOC_PAGES: list[tuple[str, str, str]] = [
    ("schema.md", "Schema", "Understand how Loupe derives document structure and validates evolving data."),
    ("configuration.md", "Configuration", "Tune searchable, filterable, sortable, and typo-tolerance behavior."),
    ("indexing.md", "Indexing", "Add, batch, and remove documents efficiently while preserving schema integrity."),
    ("searching.md", "Searching", "Query, filter, sort, facet, paginate, and highlight search results."),
    ("browsing.md", "Browsing", "Walk the index for exports, data inspection, or internal tooling."),
    ("ranking.md", "Ranking", "See how Loupe scores relevance and how to improve result quality."),
    ("tokenizer.md", "Tokenizer", "Learn how language detection, tokenization, and stemming shape the index."),
    ("performance.md", "Performance", "Benchmark the engine and understand the tradeoffs behind fast lookups."),
    ("blog_post.md", "Background / Blog Post", "Read the longer story behind the project and the constraints that shaped it."),
]

SOURCE_TO_TARGET = {"README.md": "index.md"} | {f"docs/{filename}": filename for filename, _, _ in DOC_PAGES}
REFERENCE_DEFINITION_RE = re.compile(r"(?m)^\[[^\]]+\]:\s+\S+.*$")
INLINE_LINK_RE = re.compile(r"(?<!!)\[([^\]]+)\]\(([^)]+)\)")
REFERENCE_LINK_RE = re.compile(r"(?m)^(\[[^\]]+\]:\s*)(\S+)(.*)$")
README_HEADER_RE = re.compile(r"\A<div align=\"center\">.*?</div>\s*<br/>\s*", re.DOTALL)
CODE_SPAN_RE = re.compile(r"`([^`]+)`")
STRONG_SPAN_RE = re.compile(r"\*\*([^*]+)\*\*")


def main() -> None:
    prepare_generated_directory()
    copy_static_assets()

    readme = README_PATH.read_text(encoding="utf-8")
    generated_index = build_home_page(readme)
    write_page("index.md", rewrite_links(generated_index, "README.md", "index.md"))

    for filename, _, _ in DOC_PAGES:
        source_path = DOCS_SOURCE_DIR / filename
        content = source_path.read_text(encoding="utf-8")

        if filename == "blog_post.md":
            content = "---\ntemplate: background.html\n---\n\n" + content

        target = SOURCE_TO_TARGET[f"docs/{filename}"]
        write_page(target, rewrite_links(content, f"docs/{filename}", target))


def prepare_generated_directory() -> None:
    if GENERATED_DOCS_DIR.exists():
        shutil.rmtree(GENERATED_DOCS_DIR)

    GENERATED_DOCS_DIR.mkdir(parents=True, exist_ok=True)


def copy_static_assets() -> None:
    if THEME_ASSETS_DIR.exists():
        shutil.copytree(THEME_ASSETS_DIR, GENERATED_DOCS_DIR / "assets")

    logo_target = GENERATED_DOCS_DIR / "assets" / "logo"
    logo_target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(LOGO_DIR, logo_target, dirs_exist_ok=True)


def build_home_page(readme: str) -> str:
    cleaned_readme = strip_readme_header(readme)
    references = "\n".join(REFERENCE_DEFINITION_RE.findall(cleaned_readme))
    content_without_refs = REFERENCE_DEFINITION_RE.sub("", cleaned_readme).strip()

    intro_text, features, remaining_content = split_home_header(content_without_refs)

    parts = [
        "---",
        "title: Home",
        "template: home.html",
        "hide:",
        "  - toc",
        "---",
        "",
        '<div class="loupe-section-label">Capability Surface</div>',
        "",
        intro_text,
        "",
        build_feature_grid(features),
    ]

    if remaining_content.strip():
        parts.extend(["", remaining_content.strip()])

    if references:
        parts.extend(["", references.strip()])

    return "\n".join(parts).strip() + "\n"


def strip_readme_header(readme: str) -> str:
    return README_HEADER_RE.sub("", readme, count=1).strip()


def build_feature_grid(features: list[str]) -> str:
    cards = ['<ul class="loupe-feature-list">']

    for feature in features:
        cards.extend(
            [
                '  <li class="loupe-feature-list__item">',
                f"    {format_inline_code(feature)}",
                "  </li>",
            ]
        )

    cards.append("</ul>")

    return "\n".join(cards)


def format_inline_code(value: str) -> str:
    escaped = html.escape(value)
    escaped = STRONG_SPAN_RE.sub(r"<strong>\1</strong>", escaped)
    return CODE_SPAN_RE.sub(r"<code>\1</code>", escaped)


def extract_markdown_list_items(markdown: str) -> list[str]:
    items: list[str] = []
    current: list[str] = []

    for line in markdown.splitlines():
        stripped = line.strip()

        if stripped.startswith("* "):
            if current:
                items.append(" ".join(current).strip())

            current = [stripped[2:].strip()]
            continue

        if current and line.startswith("  "):
            current.append(stripped)
            continue

        if current:
            items.append(" ".join(current).strip())
            current = []

    if current:
        items.append(" ".join(current).strip())

    return items


def split_home_header(markdown: str) -> tuple[str, list[str], str]:
    lines = markdown.splitlines()
    intro_lines: list[str] = []
    feature_lines: list[str] = []
    remaining_lines: list[str] = []
    state = "intro"

    for line in lines:
        stripped = line.strip()

        if state == "intro":
            if stripped.startswith("* "):
                state = "features"
                feature_lines.append(line)
                continue

            intro_lines.append(line)
            continue

        if state == "features":
            if stripped.startswith("* ") or (feature_lines and line.startswith("  ")):
                feature_lines.append(line)
                continue

            state = "remaining"

        if state == "remaining":
            remaining_lines.append(line)

    intro_text = "\n".join(intro_lines).strip()
    features = extract_markdown_list_items("\n".join(feature_lines))
    remaining_content = "\n".join(remaining_lines).strip()

    return intro_text, features, remaining_content


def rewrite_links(content: str, source_path: str, current_target: str) -> str:
    content = INLINE_LINK_RE.sub(
        lambda match: f"[{match.group(1)}]({rewrite_target(match.group(2), source_path, current_target)})",
        content,
    )

    return REFERENCE_LINK_RE.sub(
        lambda match: f"{match.group(1)}{rewrite_target(match.group(2), source_path, current_target)}{match.group(3)}",
        content,
    )


def rewrite_target(raw_target: str, source_path: str, current_target: str) -> str:
    target = raw_target.strip()

    if target.startswith(("#", "http://", "https://", "mailto:")):
        return target

    path_part, suffix = split_suffix(target)
    resolved = posixpath.normpath(posixpath.join(posixpath.dirname(source_path), path_part))

    if not path_part.endswith(".md"):
        if repository_path_exists(resolved):
            return REPOSITORY_BLOB_BASE + resolved + suffix

        return target

    if resolved not in SOURCE_TO_TARGET:
        if repository_path_exists(resolved):
            return REPOSITORY_BLOB_BASE + resolved + suffix

        return target

    destination_target = SOURCE_TO_TARGET[resolved]
    return relative_doc_path(current_target, destination_target) + suffix


def split_suffix(target: str) -> tuple[str, str]:
    for separator in ("#", "?"):
        if separator in target:
            index = target.index(separator)
            return target[:index], target[index:]

    return target, ""


def relative_doc_path(current_target: str, destination_target: str) -> str:
    current_directory = posixpath.dirname(current_target) or "."
    return posixpath.relpath(destination_target, start=current_directory)


def repository_path_exists(path: str) -> bool:
    return (ROOT / Path(path)).exists()


def write_page(filename: str, content: str) -> None:
    target_path = GENERATED_DOCS_DIR / filename
    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(content, encoding="utf-8")


if __name__ == "__main__":
    main()

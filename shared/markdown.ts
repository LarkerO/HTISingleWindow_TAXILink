import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';

const markdown = new MarkdownIt({ html: false, linkify: true, breaks: true });
export function renderMarkdown(value: string): string {
  return DOMPurify.sanitize(markdown.render(value));
}

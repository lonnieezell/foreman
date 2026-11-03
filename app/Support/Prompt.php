<?php

namespace App\Support;

/** Fills the `{{placeholders}}` in the prompt templates under `resources/prompts/`. */
final class Prompt
{
    /** @param  array<string,string>  $values  placeholder name => value */
    public static function render(string $name, array $values): string
    {
        return self::fill(self::template($name), $values);
    }

    /** The template's text, unfilled. */
    public static function template(string $name): string
    {
        return file_get_contents(resource_path("prompts/{$name}.md"));
    }

    /**
     * Fill a template given as text, such as a repository's own prompt. A value is never filled again.
     *
     * @param  array<string,string>  $values  placeholder name => value
     */
    public static function fill(string $template, array $values): string
    {
        return strtr($template, collect($values)->mapWithKeys(fn ($value, $name) => ["{{{$name}}}" => $value])->all());
    }
}

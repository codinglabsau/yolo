<?php

namespace Codinglabs\Yolo\Resources;

/**
 * Marker for a resource that belongs to a single application (the exclusive,
 * per-app resources — not shared infrastructure). Tagging inspects this marker
 * to stamp the `yolo:app` owner tag, which is what lets `yolo audit` attribute a
 * resource to its app and flag drift. Declare it and ResolvesTags does the rest
 * — there's no per-resource tag to remember.
 */
interface AppScoped {}

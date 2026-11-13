<?php

namespace Kraenkvisuell\StatamicReps\StaticCaching;

/**
 * The warm job of reps:warm runs (deploys, saves that change every page). Its own class gives it its own unique locks: a deploy
 * job still waiting for a page must not swallow the re-caching of that page after a save, which
 * goes onto a queue Horizon works through first.
 */
class DeployWarmJob extends WarmJob {}

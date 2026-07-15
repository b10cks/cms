import type { Component } from 'vue'

import BadgeInfoIcon from './BadgeInfoIcon.vue'
import BellIcon from './BellIcon.vue'
import BlocksIcon from './BlocksIcon.vue'
import CircleHelpIcon from './CircleHelpIcon.vue'
import CompassIcon from './CompassIcon.vue'
import DatabaseZapIcon from './DatabaseZapIcon.vue'
import FeatherIcon from './FeatherIcon.vue'
import HistoryIcon from './HistoryIcon.vue'
import HomeIcon from './HomeIcon.vue'
import ImagesIcon from './ImagesIcon.vue'
import MessageSquareIcon from './MessageSquareIcon.vue'
import NetworkIcon from './NetworkIcon.vue'
import PencilIcon from './PencilIcon.vue'
import RocketIcon from './RocketIcon.vue'
import ScrollTextIcon from './ScrollTextIcon.vue'
import SettingsIcon from './SettingsIcon.vue'
import ShapesIcon from './ShapesIcon.vue'
import SplitIcon from './SplitIcon.vue'
import WaypointsIcon from './WaypointsIcon.vue'
import WrenchIcon from './WrenchIcon.vue'

/**
 * Animated replacements for lucide icons, keyed by the icon names used in
 * navigation config. They animate when hovered directly or when any
 * ancestor with the `icon-anim` class is hovered.
 */
export const animatedIcons: Record<string, Component> = {
  'lucide:home': HomeIcon,
  'lucide:compass': CompassIcon,
  'lucide:network': NetworkIcon,
  'lucide:feather': FeatherIcon,
  'lucide:blocks': BlocksIcon,
  'lucide:images': ImagesIcon,
  'lucide:shapes': ShapesIcon,
  'lucide:database-zap': DatabaseZapIcon,
  'lucide:split': SplitIcon,
  'lucide:rocket': RocketIcon,
  'lucide:scroll-text': ScrollTextIcon,
  'lucide:waypoints': WaypointsIcon,
  'lucide:settings': SettingsIcon,
  'lucide:bell': BellIcon,
  'lucide:circle-question-mark': CircleHelpIcon,
  'lucide:pencil': PencilIcon,
  'lucide:wrench': WrenchIcon,
  'lucide:badge-info': BadgeInfoIcon,
  'lucide:message-square': MessageSquareIcon,
  'lucide:history': HistoryIcon,
}

export {
  BadgeInfoIcon,
  BellIcon,
  BlocksIcon,
  CircleHelpIcon,
  CompassIcon,
  DatabaseZapIcon,
  FeatherIcon,
  HistoryIcon,
  HomeIcon,
  ImagesIcon,
  MessageSquareIcon,
  NetworkIcon,
  PencilIcon,
  RocketIcon,
  ScrollTextIcon,
  SettingsIcon,
  ShapesIcon,
  SplitIcon,
  WaypointsIcon,
  WrenchIcon,
}

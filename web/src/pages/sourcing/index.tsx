/**
 * Procurement → Supplier quotations.
 *
 * Three screens: the sourcing workspace, one enquiry, and the editor that
 * raises one. They are exported from here so the router imports a module
 * rather than a file, and so the workspace can be split into pieces without
 * every import in the application moving.
 */

export { RfqList } from './SourcingPage'
export { RfqDetail } from './RfqDetail'
export { RfqEditor } from './RfqEditor'

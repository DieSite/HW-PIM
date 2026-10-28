/**
 * This will track all the images and fonts for publishing.
 */
import.meta.glob(["../images/**", "../fonts/**"], { eager: true, query: "?url", import: "default" });

 
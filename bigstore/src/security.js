/**
 * Middleware ensuring BigStore only responds to authorized backend requests
 * Spec Section 42: "BigStore should reject any request without valid backend credentials."
 */
function authenticateInternalService(req, res, next) {
  const secret = process.env.INTERNAL_BIGSTORE_SECRET;
  
  if (!secret) {
    console.error('[BigStore Security] CRITICAL: INTERNAL_BIGSTORE_SECRET is not configured.');
    return res.status(500).json({
      success: false,
      error: {
        code: 'INTERNAL_CONFIGURATION_ERROR',
        message: 'Internal service authentication is unconfigured.',
      },
    });
  }

  const token = req.headers['x-internal-service-token'];

  if (!token || token !== secret) {
    return res.status(401).json({
      success: false,
      error: {
        code: 'UNAUTHORIZED_SERVICE',
        message: 'Invalid or missing internal service authorization token.',
      },
    });
  }

  next();
}

module.exports = {
  authenticateInternalService,
};
